<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Session\Backend;

use Moselwal\KeyValueStore\Connection\ConnectionFactoryInterface;
use Moselwal\KeyValueStore\Connection\KeyValueConnectionFactory;
use Redis;
// TYPO3 11-13 has this exception; TYPO3 14 removed it.
// Use PHP's built-in \InvalidArgumentException for cross-version compatibility.
use TYPO3\CMS\Core\Session\Backend\Exception\SessionNotCreatedException;
use TYPO3\CMS\Core\Session\Backend\Exception\SessionNotFoundException;
use TYPO3\CMS\Core\Session\Backend\Exception\SessionNotUpdatedException;
use TYPO3\CMS\Core\Session\Backend\SessionBackendInterface;

/**
 * Redis/Valkey session backend with optional Sentinel and TLS/mTLS support.
 *
 * Implements the full TYPO3 SessionBackendInterface contract:
 *   - Session records are stored as JSON-encoded arrays.
 *   - ses_id is always forced to $sessionId on write.
 *   - ses_tstamp is updated automatically on every write.
 *   - get() throws SessionNotFoundException when the session is missing.
 *   - set() throws SessionNotCreatedException on write failure.
 *   - update() throws SessionNotUpdatedException when the session is missing or write fails.
 *
 * Configuration via $GLOBALS['TYPO3_CONF_VARS']['SYS']['session']['BE|FE']:
 *
 *   'backend' => \Moselwal\KeyValueStore\Session\Backend\KeyValueSessionBackend::class,
 *   'options'  => [
 *     'hostname'             => 'keyvaluecache',  // ignored when sentinel=true
 *     'port'                 => 6379,
 *     'database'             => 2,
 *     'sessionLifetime'      => 3600,             // Redis TTL in seconds; default 3600
 *
 *     // Session-ID hashing (security default since v4.4)
 *     'hashSessionIds'       => true,             // HMAC ses_id before persisting as cache key
 *     'hashSecret'           => '<random>',       // overrides TYPO3_CONF_VARS[SYS][encryptionKey]
 *
 *     // Auth — phpredis-style 'auth' or legacy 'username'/'password'
 *     'auth'                 => ['myuser', 'mypassword'],  // ACL
 *     // -- or --
 *     'password'             => 'secret',
 *     'username'             => 'myuser',                  // optional ACL username
 *
 *     'connectTimeout'       => 2.5,    // seconds (alias: connectionTimeout)
 *     'readTimeout'          => 1.0,    // seconds
 *     'persistentConnection' => true,
 *     'persistentId'         => 'typo3-session-fe',
 *     'prefix'               => 'typo3:sess:fe:',
 *
 *     // Backoff (optional, phpredis >= 6.0)
 *     'backoff' => [
 *         'algorithm' => \Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER,
 *         'base' => 500,
 *         'cap'  => 750,
 *     ],
 *
 *     // TLS/mTLS (optional)
 *     'tls'                  => true,
 *     'verify_peer'          => true,
 *     'verify_peer_name'     => true,
 *     'peer_name'            => 'valkey.internal',
 *     'ca_file'              => '/run/tls/ca.crt',
 *     'cert_file'            => '/run/tls/httpd.crt',  // mTLS
 *     'key_file'             => '/run/tls/httpd.key',  // mTLS
 *     'allow_self_signed'    => false,
 *
 *     // Sentinel (optional)
 *     'sentinel'             => true,
 *     'sentinel_host'        => 'sentinel',
 *     'sentinel_port'        => 26379,
 *     'sentinel_service'     => 'mymaster',
 *     'sentinel_password'    => null,
 *   ],
 */
final class KeyValueSessionBackend implements SessionBackendInterface
{
    private const DEFAULT_PORT = 6379;
    private const DEFAULT_DB = 0;
    private const DEFAULT_CONNECT_TIMEOUT = 1.0;
    private const DEFAULT_READ_TIMEOUT = 1.0;
    private const DEFAULT_SESSION_LIFETIME = 3600;
    private const HASH_ALGO = 'sha256';

    /**
     * Atomic rename of a session key while preserving TTL. Doing this with
     * separate GET/SETEX/DEL commands races with concurrent update() writes:
     * an update landing between our GET and SETEX would be silently
     * overwritten by the older session data we copied into the new key.
     */
    private const RENEW_SCRIPT = <<<'LUA'
        local val = redis.call('GET', KEYS[1])
        if not val then return 0 end
        local ttl = redis.call('TTL', KEYS[1])
        if ttl <= 0 then ttl = tonumber(ARGV[1]) end
        redis.call('SETEX', KEYS[2], ttl, val)
        redis.call('DEL', KEYS[1])
        return 1
        LUA;

    /** @var array<string,mixed> */
    private array $options = [];

    private ?\Redis $redis = null;

    private string $prefix = 'typo3:sess:';

    /**
     * HMAC secret for hashing session IDs before persistence. Pulled from
     * the `hashSecret` option (preferred) or from
     * `$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']` as fallback.
     */
    private string $hashSecret = '';

    /**
     * Whether to HMAC-hash the session ID before using it as a Redis key.
     * Defaults to true. Disabling is supported for legacy migrations only —
     * raw session IDs in Redis allow any operator with read access to the
     * cache to lift active sessions.
     * @param array<array-key, mixed> $configuration
     */
    private bool $hashSessionIds = true;

    private ConnectionFactoryInterface $factory;

    public function __construct()
    {
        $this->factory = new KeyValueConnectionFactory();
    }

    // -------------------------------------------------------------------------
    // SessionBackendInterface
    // -------------------------------------------------------------------------

    /** @param array<array-key, mixed> $configuration */
    public function initialize(string $identifier, array $configuration): void
    {
        $this->options = $configuration;
        $this->prefix = (string) ($configuration['prefix']
            ?? ('typo3:sess:' . strtolower($identifier) . ':'));
        $this->hashSessionIds = (bool) ($configuration['hashSessionIds'] ?? true);
        $this->hashSecret = trim((string) (
            $configuration['hashSecret']
            ?? ($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '')
        ));
        $this->redis = null;
        $this->validateConfiguration();
    }

    public function validateConfiguration(): void
    {
        if (isset($this->options['sentinel']) && true === (bool) $this->options['sentinel']) {
            if ('' === trim((string) ($this->options['sentinel_host'] ?? ''))) {
                throw new \InvalidArgumentException('Redis sentinel=true but sentinel_host is missing', 1730001001);
            }
            if ('' === trim((string) ($this->options['sentinel_service'] ?? ''))) {
                throw new \InvalidArgumentException('Redis sentinel=true but sentinel_service is missing', 1730001004);
            }
        } else {
            $host = trim((string) ($this->options['hostname'] ?? $this->options['host'] ?? ''));
            if ('' === $host) {
                throw new \InvalidArgumentException('Redis hostname is missing', 1730001002);
            }
        }

        $db = (int) ($this->options['database'] ?? self::DEFAULT_DB);
        if ($db < 0) {
            throw new \InvalidArgumentException('Redis database must be >= 0', 1730001003);
        }

        // Session-ID-Hashing requires a non-empty secret. Without it the
        // session lookup would still work, but every cache-key would be a
        // hash of the empty-string-keyed HMAC — predictable and useless
        // as a security measure. Fail loudly instead.
        if ($this->hashSessionIds && '' === $this->hashSecret) {
            throw new \InvalidArgumentException(
                'KeyValueSessionBackend: hashSessionIds=true but no hashSecret/$TYPO3_CONF_VARS[SYS][encryptionKey] is configured',
                1733012001
            );
        }
    }

    /**
     * Read session data.
     *
     * @throws SessionNotFoundException when the session does not exist
     * @return array<array-key, mixed>
     */
    public function get(string $sessionId): array
    {
        try {
            $raw = $this->getRedis()->get($this->key($sessionId));
        } catch (\RedisException $e) {
            $this->resetRedis();
            throw new SessionNotFoundException('Redis error while reading session "' . $sessionId . '": ' . $e->getMessage(), 1730001011, $e);
        }

        if (false === $raw) {
            throw new SessionNotFoundException('Session "' . $sessionId . '" not found', 1730001010);
        }

        $record = json_decode((string) $raw, true);

        return is_array($record) ? $record : [];
    }

    /**
     * List all sessions as an array of session record arrays.
     *
     * Each SCAN page is resolved through a single MGET roundtrip instead
     * of N individual GETs.
     *
     * **phpredis 6.x SCAN semantics** — easy to get wrong:
     *   - The cursor MUST start as `null`. phpredis 6.3 treats `int 0`
     *     as "iteration finished" and returns `false` immediately without
     *     ever talking to the server. `null` triggers the real first
     *     request; phpredis updates the by-ref cursor on every subsequent
     *     call.
     *   - Exit the loop when the cursor becomes `0` (phpredis signals
     *     end-of-iteration that way), not when it falls below 1 —
     *     `!== 0` keeps us safe against any string-typed cursor variants.
     * @return list<array<array-key, mixed>>
     */
    public function getAll(): array
    {
        try {
            $redis = $this->getRedis();
            $sessions = [];
            $cursor = null;

            do {
                $keys = $redis->scan($cursor, $this->prefix . '*', 100);
                if (false === $keys || [] === $keys) {
                    continue;
                }
                $values = $redis->mget($keys);
                if (!is_array($values)) {
                    continue;
                }
                foreach ($values as $raw) {
                    if (false === $raw || null === $raw) {
                        // Concurrent expiry between SCAN and MGET.
                        continue;
                    }
                    $record = json_decode((string) $raw, true);
                    if (is_array($record)) {
                        $sessions[] = $record;
                    }
                }
            } while (0 !== (int) $cursor);

            return $sessions;
        } catch (\RedisException) {
            $this->resetRedis();

            return [];
        }
    }

    /**
     * Write new session data.
     *
     * Enforces ses_id = $sessionId and updates ses_tstamp per interface contract.
     *
     * @throws SessionNotCreatedException on write failure
     * @param array<array-key, mixed> $sessionData
     *
     * @return array<array-key, mixed>
     */
    public function set(string $sessionId, array $sessionData): array
    {
        $ttl = max(1, (int) ($this->options['sessionLifetime'] ?? self::DEFAULT_SESSION_LIFETIME));

        // Interface contract: ses_id is always overwritten; ses_tstamp is updated.
        $sessionData['ses_id'] = $sessionId;
        $sessionData['ses_tstamp'] = $GLOBALS['EXEC_TIME'] ?? time();

        try {
            $encoded = json_encode($sessionData, JSON_THROW_ON_ERROR);
            $ok = $this->getRedis()->setex($this->key($sessionId), $ttl, $encoded);
            if (!$ok) {
                throw new SessionNotCreatedException('Could not write session "' . $sessionId . '" to Redis', 1730001020);
            }

            return $sessionData;
        } catch (\RedisException $e) {
            $this->resetRedis();
            throw new SessionNotCreatedException('Redis error while creating session "' . $sessionId . '": ' . $e->getMessage(), 1730001021, $e);
        }
    }

    /**
     * Update existing session data (partial merge).
     *
     * Preserves the existing TTL. Enforces ses_id = $sessionId and updates ses_tstamp.
     *
     * @throws SessionNotUpdatedException when the session is missing or the write fails
     * @param array<array-key, mixed> $sessionData
     *
     * @return array<array-key, mixed>
     */
    public function update(string $sessionId, array $sessionData): array
    {
        $maxRetries = 2;

        for ($attempt = 0; $attempt < $maxRetries; ++$attempt) {
            try {
                $redis = $this->getRedis();
                $key = $this->key($sessionId);

                $redis->watch($key);

                $raw = $redis->get($key);
                if (false === $raw) {
                    $redis->unwatch();
                    throw new SessionNotUpdatedException('Session "' . $sessionId . '" not found, cannot update', 1730001030);
                }

                $existing = json_decode((string) $raw, true);
                $record = is_array($existing) ? $existing : [];

                // Merge partial update on top of the existing record.
                $record = array_merge($record, $sessionData);

                // Interface contract: ses_id is always overwritten; ses_tstamp is updated.
                $record['ses_id'] = $sessionId;
                $record['ses_tstamp'] = $GLOBALS['EXEC_TIME'] ?? time();

                // Preserve the existing TTL; fall back to configured lifetime when the
                // key has no TTL (-1) or has already expired (-2).
                $ttl = (int) $redis->ttl($key);
                if ($ttl <= 0) {
                    $ttl = (int) ($this->options['sessionLifetime'] ?? self::DEFAULT_SESSION_LIFETIME);
                }

                $encoded = json_encode($record, JSON_THROW_ON_ERROR);

                $redis->multi();
                $redis->setex($key, max(1, $ttl), $encoded);
                $result = $redis->exec();

                if (false === $result) {
                    // WATCH detected a concurrent modification; retry
                    continue;
                }

                return $record;
            } catch (\RedisException $e) {
                $this->resetRedis();
                throw new SessionNotUpdatedException('Redis error while updating session "' . $sessionId . '": ' . $e->getMessage(), 1730001032, $e);
            }
        }

        throw new SessionNotUpdatedException('Could not write updated session "' . $sessionId . '" to Redis after ' . $maxRetries . ' attempts due to concurrent modifications', 1730001031);
    }

    public function remove(string $sessionId): bool
    {
        try {
            return $this->getRedis()->del($this->key($sessionId)) > 0;
        } catch (\RedisException) {
            $this->resetRedis();

            return false;
        }
    }

    public function collectGarbage(int $maximumLifetime, int $maximumAnonymousLifetime = 0): void
    {
        // Redis TTL handles expiry automatically; nothing to do here.
    }

    /**
     * Rename a session key while preserving TTL (session fixation protection).
     * Not part of SessionBackendInterface.
     *
     * Implemented as a single EVAL so the read/write/delete sequence is
     * atomic relative to concurrent update() callers.
     */
    public function renew(string $oldSessionId, string $newSessionId): bool
    {
        try {
            $redis = $this->getRedis();
            $fallbackTtl = (int) ($this->options['sessionLifetime'] ?? self::DEFAULT_SESSION_LIFETIME);
            $result = $redis->eval(
                self::RENEW_SCRIPT,
                [$this->key($oldSessionId), $this->key($newSessionId), (string) $fallbackTtl],
                2,
            );

            return 1 === (int) $result;
        } catch (\RedisException) {
            $this->resetRedis();

            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Map a session ID to its Redis cache key.
     *
     * Session IDs land in Redis hashed by default — without that an
     * operator with read access to the cache could lift any active
     * session by reading the raw key and replaying it as a cookie. The
     * cookie value the user holds is unchanged; only the persistence
     * key is rewritten. `ses_id` inside the JSON payload still holds
     * the plain session ID so TYPO3's interface contract stays intact.
     */
    private function key(string $sessionId): string
    {
        if ($this->hashSessionIds) {
            return $this->prefix . hash_hmac(self::HASH_ALGO, $sessionId, $this->hashSecret);
        }

        return $this->prefix . $sessionId;
    }

    /**
     * Connect with up to three attempts. The previous backoff (50/100/150ms)
     * could block the whole request for ~300ms on a transient Valkey blip
     * before the SessionNotCreatedException surfaced. We use decorrelated
     * jitter (10ms base, 100ms cap) instead — same retry count, ~3× faster
     * fail and less synchronised retry storms across pods.
     */
    private function getRedis(): \Redis
    {
        if ($this->redis instanceof \Redis) {
            return $this->redis;
        }

        $factoryOptions = $this->buildFactoryOptions();

        $last = null;
        $base = 10_000;
        $cap = 100_000;
        $sleep = $base;
        for ($i = 0; $i < 3; ++$i) {
            try {
                $this->redis = $this->factory->create($factoryOptions);
                $this->redis->ping();

                return $this->redis;
            } catch (\RedisException $e) {
                $last = $e;
                $this->redis = null;
                if ($i < 2) {
                    usleep($sleep);
                    $sleep = min($cap, random_int($base, $sleep * 3));
                }
            }
        }

        throw $last ?? new \RedisException('Could not connect to Redis');
    }

    /**
     * Reset the connection so the next call to getRedis() will reconnect.
     */
    private function resetRedis(): void
    {
        $this->redis = null;
    }

    /** @return array<string, mixed> */
    private function buildFactoryOptions(): array
    {
        $opts = [
            'host' => trim((string) ($this->options['hostname'] ?? $this->options['host'] ?? '')),
            'port' => (int) ($this->options['port'] ?? self::DEFAULT_PORT),
            'connectTimeout' => (float) ($this->options['connectTimeout']
                ?? $this->options['connectionTimeout']
                ?? self::DEFAULT_CONNECT_TIMEOUT),
            'readTimeout' => (float) ($this->options['readTimeout'] ?? self::DEFAULT_READ_TIMEOUT),
            'database' => (int) ($this->options['database'] ?? self::DEFAULT_DB),
            'lazy' => true,
        ];

        $persistentConnection = (bool) ($this->options['persistentConnection'] ?? false);
        $persistentId = trim((string) ($this->options['persistentId'] ?? ''));
        if ($persistentConnection) {
            $opts['persistent'] = '' !== $persistentId ? $persistentId : true;
        }

        if (array_key_exists('auth', $this->options)) {
            $opts['auth'] = $this->options['auth'];
        } else {
            $password = (string) ($this->options['password'] ?? '');
            $username = (string) ($this->options['username'] ?? '');
            if ('' !== $password) {
                $opts['auth'] = '' !== $username ? [$username, $password] : $password;
            }
        }

        foreach ([
            'backoff',
            'tls', 'ca_file', 'cert_file', 'key_file', 'peer_name',
            'verify_peer', 'verify_peer_name', 'allow_self_signed',
            'sentinel', 'sentinel_host', 'sentinel_port', 'sentinel_service', 'sentinel_password',
        ] as $k) {
            if (array_key_exists($k, $this->options)) {
                $opts[$k] = $this->options[$k];
            }
        }

        return $opts;
    }
}
