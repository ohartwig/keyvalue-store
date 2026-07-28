<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Locking;

use Moselwal\KeyValueStore\Connection\KeyValueConnectionFactory;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireException;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\Exception\LockCreateException;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

final class KeyValueLockingStrategy implements LockingStrategyInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const DEFAULT_PRIORITY = 95;

    /**
     * Conservative upper bound for the blocking-acquire retry loop, used
     * when the operator does not configure `maxAcquireAttempts`. With the
     * default TTL of 30 s this caps the worst-case wait at ~50 minutes.
     */
    private const DEFAULT_ACQUIRE_BLOCKING_MAX_ATTEMPTS = 100;

    private \Redis $redis;
    private KeyValueConnectionFactory $factory;

    private string $name;
    private string $mutexName;
    private string $value;

    private bool $isAcquired = false;
    private int $ttl = 30;
    private int $maxAcquireAttempts = self::DEFAULT_ACQUIRE_BLOCKING_MAX_ATTEMPTS;

    public function __construct(mixed $subject)
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][self::class]['options'] ?? null;
        if (!is_array($configuration)) {
            throw new LockCreateException('No configuration for KeyValueLockingStrategy found.', 1700000001);
        }

        $host = (string) ($configuration['host'] ?? $configuration['hostname'] ?? '');
        if ('' === $host && (!isset($configuration['sentinel']) || true !== (bool) $configuration['sentinel'])) {
            throw new LockCreateException('No host configured for Redis locking.', 1700000002);
        }

        if (isset($configuration['ttl'])) {
            $this->ttl = max(1, (int) $configuration['ttl']);
        }
        if (isset($configuration['maxAcquireAttempts'])) {
            $this->maxAcquireAttempts = max(1, (int) $configuration['maxAcquireAttempts']);
        }

        $keyPrefix = sha1(($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? 'init') . '_KEYVALUE_LOCKING');
        $this->name = sprintf('%s:lock:name:%s', $keyPrefix, $subject);
        $this->mutexName = sprintf('%s:lock:mutex:%s', $keyPrefix, $subject);
        $this->value = bin2hex(random_bytes(16));

        $this->factory = new KeyValueConnectionFactory();
        $this->redis = $this->factory->create($this->mapOptions($configuration));
    }

    public static function getCapabilities(): int
    {
        return self::LOCK_CAPABILITY_EXCLUSIVE | self::LOCK_CAPABILITY_NOBLOCK;
    }

    public static function getPriority(): int
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][self::class]['options'] ?? null;

        return (is_array($configuration) && isset($configuration['priority']))
            ? (int) $configuration['priority']
            : self::DEFAULT_PRIORITY;
    }

    public function acquire($mode = self::LOCK_CAPABILITY_EXCLUSIVE): bool
    {
        if ($this->isAcquired) {
            return true;
        }
        if (0 === ($mode & self::LOCK_CAPABILITY_EXCLUSIVE)) {
            throw new LockAcquireException('Insufficient capabilities.', 1700000010);
        }

        $nonBlocking = (bool) ($mode & self::LOCK_CAPABILITY_NOBLOCK);

        if ($nonBlocking) {
            if (!$this->isAcquired = $this->tryLock()) {
                throw new LockAcquireWouldBlockException('Could not acquire exclusive lock (non-blocking).', 1700000011);
            }
        } else {
            $attempts = 0;
            while (!$this->isAcquired = $this->tryLock()) {
                if (++$attempts > $this->maxAcquireAttempts) {
                    throw new LockAcquireException(sprintf('Could not acquire exclusive lock after %d blocking attempts.', $this->maxAcquireAttempts), 1700000013);
                }
                if (null === $this->wait()) {
                    throw new LockAcquireException('Could not acquire exclusive lock (blocking).', 1700000012);
                }
            }
        }

        return $this->isAcquired;
    }

    public function release(): bool
    {
        if (!$this->isAcquired) {
            return true;
        }
        $released = $this->unlockAndSignal();
        $this->isAcquired = false;

        return $released;
    }

    public function destroy(): void
    {
        $this->release();
    }

    public function isAcquired(): bool
    {
        return $this->isAcquired;
    }

    public function __destruct()
    {
        $this->release();
    }

    /**
     * Attempt a single SET NX EX to acquire the lock.
     *
     * `false` from phpredis here means either "lock already held" (the
     * expected, frequent case under contention) or "Redis is unreachable"
     * (the actual incident case). We can only tell the two apart by
     * catching the Throwable — the Throwable path logs at `critical`,
     * the SET-returns-false path stays silent so a busy lock under
     * contention does not flood the log.
     */
    private function tryLock(): bool
    {
        try {
            return (bool) $this->redis->set($this->name, $this->value, ['NX', 'EX' => $this->ttl]);
        } catch (\Throwable $e) {
            $this->logger?->critical('Could not acquire lock in Redis', ['exception' => $e]);

            return false;
        }
    }

    /**
     * Block on the mutex notification queue until the current holder
     * signals release. Returns the signalled value or null on timeout/error.
     *
     * The blocking timeout reuses the configured TTL — querying the
     * server for the current key TTL would be an extra roundtrip for
     * no gain: all holders use the same configured TTL, so this value
     * is an upper bound on how long the lock can stay held.
     */
    private function wait(): ?string
    {
        try {
            $result = $this->redis->blPop([$this->mutexName], max(1, $this->ttl));

            return $result[1] ?? null;
        } catch (\Throwable $e) {
            $this->logger?->critical('Failure while waiting on Redis mutex', ['exception' => $e]);

            return null;
        }
    }

    /**
     * Atomically: verify token ownership, delete lock key, push release signal.
     */
    private function unlockAndSignal(): bool
    {
        try {
            $script = <<<'LUA'
                    if redis.call("GET", KEYS[1]) == ARGV[1] then
                        redis.call("DEL", KEYS[1])
                        redis.call("RPUSH", KEYS[2], ARGV[1])
                        redis.call("EXPIRE", KEYS[2], ARGV[2])
                        return 1
                    end
                    return 0
                LUA;

            return (bool) $this->redis->eval($script, [$this->name, $this->mutexName, $this->value, $this->ttl], 2);
        } catch (\Throwable $e) {
            $this->logger?->critical('Failure while unlocking in Redis', ['exception' => $e]);

            return false;
        }
    }

    /**
     * Map TYPO3_CONF_VARS locking configuration to KeyValueConnectionFactory options.
     *
     * phpredis camelCase keys are primary; legacy snake_case/TYPO3-style keys are aliased.
     * @param array<string, mixed> $cfg
     *
     * @return array<string, mixed>
     */
    private function mapOptions(array $cfg): array
    {
        $opts = [
            'host' => (string) ($cfg['host'] ?? $cfg['hostname'] ?? ''),
            'port' => (int) ($cfg['port'] ?? 6379),
            // phpredis camelCase primary, with legacy aliases
            'connectTimeout' => (float) ($cfg['connectTimeout'] ?? $cfg['connectionTimeout'] ?? $cfg['timeout'] ?? 1.0),
            'readTimeout' => (float) ($cfg['readTimeout'] ?? $cfg['read_timeout'] ?? 0.0),
            'retryInterval' => (int) ($cfg['retryInterval'] ?? $cfg['retry_interval'] ?? 0),
            'database' => (int) ($cfg['database'] ?? 0),
            // Persistent: string ID, true, or false
            'persistent' => $cfg['persistent_id']
                ?? $cfg['persistentId']
                ?? (bool) ($cfg['persistent'] ?? $cfg['persistentConnection'] ?? true),
            // Defer the connection until the first command (typically the
            // initial tryLock). Saves the ping roundtrip the factory would
            // otherwise emit during construction.
            'lazy' => true,
        ];

        // Auth: phpredis-style 'auth' takes priority over legacy username/password
        if (array_key_exists('auth', $cfg)) {
            $opts['auth'] = $cfg['auth'];
        } else {
            $password = (string) ($cfg['password'] ?? $cfg['authentication'] ?? '');
            $username = (string) ($cfg['username'] ?? '');
            if ('' !== $password) {
                $opts['auth'] = '' !== $username ? [$username, $password] : $password;
            }
        }

        // Forward phpredis options and extension-specific TLS / Sentinel keys.
        foreach ([
            'backoff',
            'tls', 'ca_file', 'cert_file', 'key_file', 'peer_name',
            'verify_peer', 'verify_peer_name', 'allow_self_signed',
            'sentinel', 'sentinel_host', 'sentinel_port', 'sentinel_service', 'sentinel_password',
        ] as $k) {
            if (array_key_exists($k, $cfg)) {
                $opts[$k] = $cfg[$k];
            }
        }

        return $opts;
    }
}
