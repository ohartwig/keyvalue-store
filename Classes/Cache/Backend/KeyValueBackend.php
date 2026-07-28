<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Cache\Backend;

use Moselwal\KeyValueStore\Connection\ConnectionFactoryInterface;
use Moselwal\KeyValueStore\Connection\KeyValueConnectionFactory;
use TYPO3\CMS\Core\Cache\Backend\RedisBackend;
use TYPO3\CMS\Core\Cache\Exception;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * TYPO3 cache backend that replaces the default RedisBackend connection logic
 * with KeyValueConnectionFactory to support TLS/mTLS and Redis Sentinel.
 *
 * Configure via $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][<name>]:
 *
 *   'backend' => \Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend::class,
 *   'options'  => [
 *       // Standard RedisBackend options (hostname, port, database, password, …) work as usual.
 *       //
 *       // TLS options:
 *       'tls'              => true,
 *       'ca_file'          => '/run/tls/ca.crt',
 *       'cert_file'        => '/run/tls/client.crt',  // mTLS only
 *       'key_file'         => '/run/tls/client.key',  // mTLS only
 *       'peer_name'        => 'redis.internal',
 *       'verify_peer'      => true,
 *       'verify_peer_name' => true,
 *       'allow_self_signed'=> false,
 *       //
 *       // Sentinel options:
 *       'sentinel'         => true,
 *       'sentinel_host'    => 'sentinel',
 *       'sentinel_port'    => 26379,
 *       'sentinel_service' => 'mymaster',
 *       'sentinel_password'=> null,
 *       //
 *       // phpredis 6.x backoff (optional):
 *       'backoff' => [
 *           'algorithm' => \Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER,
 *           'base' => 500,
 *           'cap'  => 750,
 *       ],
 *   ],
 */
final class KeyValueBackend extends RedisBackend
{
    private ConnectionFactoryInterface $factory;

    /**
     * All raw options passed by CacheManager, stored for use in buildFactoryOptions().
     * This includes TLS/sentinel/backoff keys that have no setter in RedisBackend.
     */
    private array $rawOptions = [];

    /**
     * Options that RedisBackend (or AbstractBackend) has setters for.
     * Only these are forwarded to parent::__construct() to avoid the
     * "Invalid cache backend option" InvalidArgumentException.
     */
    private const PARENT_OPTION_KEYS = [
        'hostname', 'port', 'database', 'username', 'password',
        'compression', 'compressionLevel', 'connectionTimeout',
        'persistentConnection', 'defaultLifetime',
    ];

    /**
     * Atomic write + tag-diff. Replaces TYPO3 Core's 2-3 roundtrip
     * SETEX + SMEMBERS + MULTI/PIPELINE flow with a single EVAL.
     *
     * - KEYS[1] = data key (keyPrefix + 'identData:' + identifier)
     * - KEYS[2] = identifier→tags set (keyPrefix + 'identTags:' + identifier)
     * - ARGV[1] = TTL in seconds (always > 0; caller normalises lifetime=0
     *             to FAKED_UNLIMITED_LIFETIME before invoking)
     * - ARGV[2] = data payload (already compressed if applicable)
     * - ARGV[3] = entry identifier (bare, used as the value in tag→ident sets)
     * - ARGV[4] = tag-key prefix (keyPrefix + 'tagIdents:')
     * - ARGV[5..N] = new tag names
     */
    private const SET_TAG_DIFF_SCRIPT = <<<'LUA'
        redis.call('SETEX', KEYS[1], ARGV[1], ARGV[2])
        local existing = redis.call('SMEMBERS', KEYS[2])
        local newSet = {}
        for i = 5, #ARGV do newSet[ARGV[i]] = true end
        local existingSet = {}
        for _, tag in ipairs(existing) do
            existingSet[tag] = true
            if not newSet[tag] then
                redis.call('SREM', KEYS[2], tag)
                redis.call('SREM', ARGV[4] .. tag, ARGV[3])
            end
        end
        for tag, _ in pairs(newSet) do
            if not existingSet[tag] then
                redis.call('SADD', KEYS[2], tag)
                redis.call('SADD', ARGV[4] .. tag, ARGV[3])
            end
        end
        return 1
        LUA;

    /**
     * TYPO3 14 constructor: CacheManager passes the options array directly.
     * Earlier TYPO3 versions are no longer supported (composer.json: ^14.0).
     */
    public function __construct(array $options = [])
    {
        $this->rawOptions = $options;
        $filteredOptions = array_intersect_key($options, array_flip(self::PARENT_OPTION_KEYS));
        parent::__construct(self::coerceScalarOptions($filteredOptions));
        $this->factory = new KeyValueConnectionFactory();
    }

    /**
     * Brings numeric options to the type core's setters demand.
     *
     * These options arrive from site configuration, environment variables and
     * secret placeholders, where every value is a string — `port: '6380'` is
     * ordinary YAML, not a mistake. Core's setters are typed (`setPort(int)`),
     * so an unconverted string reaches them as a TypeError and the cache cannot
     * be constructed at all. Converting here, before handing the options up, is
     * the only place that can still do it.
     *
     * Only well-formed numerics are converted. Anything else is passed through
     * untouched so core still rejects it — silently turning "six thousand" into
     * 0 would be worse than the error.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function coerceScalarOptions(array $options): array
    {
        foreach (['port', 'database', 'compressionLevel', 'defaultLifetime'] as $key) {
            if (isset($options[$key]) && is_string($options[$key]) && 1 === preg_match('/^-?\d+$/', $options[$key])) {
                $options[$key] = (int) $options[$key];
            }
        }

        // Core keeps the connection timeout as whole seconds (setConnectionTimeout(int)),
        // so a fractional value has to be rounded before it goes up. The precise
        // value is not lost: the factory reads connectTimeout from the unfiltered
        // options, where 2.5 stays 2.5.
        if (isset($options['connectionTimeout']) && is_numeric($options['connectionTimeout'])) {
            $options['connectionTimeout'] = (int) round((float) $options['connectionTimeout']);
        }

        foreach (['compression', 'persistentConnection'] as $key) {
            if (isset($options[$key]) && is_string($options[$key])) {
                $bool = filter_var($options[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if (null !== $bool) {
                    $options[$key] = $bool;
                }
            }
        }

        return $options;
    }

    /**
     * Override RedisBackend::initializeObject() to use our factory instead of
     * the built-in phpredis connect() call.
     *
     * @throws Exception if the connection cannot be established
     */
    public function initializeObject(): void
    {
        try {
            $this->redis = $this->factory->create($this->buildFactoryOptions());
            // Make SCAN loops resilient: SCAN_RETRY tells phpredis to keep
            // hitting the server until either keys are returned or the
            // cursor is exhausted. Without this, an empty SCAN page returns
            // `false` and the caller has to spin its own retry loop.
            $this->redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
            $this->applySerializerOption();
            $this->connected = true;
        } catch (\RedisException|\InvalidArgumentException $e) {
            $this->connected = false;
            throw new Exception('KeyValueBackend could not connect to Redis: ' . $e->getMessage(), 1700100001, $e);
        }
    }

    /**
     * Configure `OPT_SERIALIZER` based on the `serializer` option.
     *
     * **Default is "no phpredis-side serialisation"** — TYPO3's
     * VariableFrontend already calls `serialize()` / `unserialize()`
     * before/after invoking the backend. Activating phpredis's
     * OPT_SERIALIZER on top would double-encode on write (Lua-EVAL
     * paths skip OPT_SERIALIZER for ARGV, but inherited get() honours
     * it) and double-decode on read, breaking the cache with
     * `unserialize(): Argument #1 ($data) must be of type string, array given`.
     *
     * Operators only need to set `serializer` when they want phpredis
     * to handle the serialisation layer itself (e.g. for igbinary's
     * compact binary format on caches with deeply nested arrays).
     *
     * **Switching the serializer is destructive** — existing payloads
     * stay in the previous format and will fail to deserialize on read.
     * The operator must `FLUSHDB` the affected cache databases when
     * changing this value. See README "Serializer" section.
     *
     * Supported values when set:
     *   - (default, unset) — `Redis::SERIALIZER_NONE`. TYPO3 owns
     *     serialisation. **BC-safe; identical to v4.2.0 wire behaviour.**
     *   - 'none' — alias for the default (explicit form).
     *   - 'php' — `Redis::SERIALIZER_PHP`. Lets phpredis do the
     *     serialisation. Only safe when the calling frontend does NOT
     *     also serialise (rare; advanced setups).
     *   - 'igbinary' — `Redis::SERIALIZER_IGBINARY` if ext-igbinary is
     *     loaded; otherwise emits a notice and falls back to NONE so
     *     the cache keeps working.
     *   - 'auto' — igbinary if loaded, otherwise NONE. **Not the default**
     *     because an image update that ships ext-igbinary would otherwise
     *     silently switch the on-disk format and break existing payloads.
     */
    private function applySerializerOption(): void
    {
        $requested = $this->rawOptions['serializer'] ?? null;
        if (null === $requested) {
            // No explicit option set → leave phpredis at SERIALIZER_NONE
            // (its default). TYPO3 handles encoding above us.
            return;
        }
        $serializer = match ((string) $requested) {
            'none' => \Redis::SERIALIZER_NONE,
            'php' => \Redis::SERIALIZER_PHP,
            'igbinary' => $this->resolveIgbinaryOrFallback((string) $requested),
            'auto' => extension_loaded('igbinary') && \defined('Redis::SERIALIZER_IGBINARY')
                ? \Redis::SERIALIZER_IGBINARY
                : \Redis::SERIALIZER_NONE,
            default => throw new \InvalidArgumentException(sprintf('Unknown serializer "%s"; expected one of php|igbinary|none|auto', $requested), 1700100002),
        };
        $this->redis->setOption(\Redis::OPT_SERIALIZER, $serializer);
    }

    private function resolveIgbinaryOrFallback(string $requested): int
    {
        if (extension_loaded('igbinary') && \defined('Redis::SERIALIZER_IGBINARY')) {
            return \Redis::SERIALIZER_IGBINARY;
        }
        // The caller explicitly asked for igbinary but the extension is
        // not loaded — fall back to SERIALIZER_NONE so TYPO3 keeps owning
        // the serialisation layer (same wire format as v4.2.0). Operators
        // should treat the missing extension as a deployment bug; we do
        // not throw because a single missing extension should not take
        // the site down.
        trigger_error(
            sprintf(
                'KeyValueBackend: serializer "%s" requested but ext-igbinary is not loaded — falling back to SERIALIZER_NONE',
                $requested,
            ),
            E_USER_NOTICE,
        );

        return \Redis::SERIALIZER_NONE;
    }

    /**
     * Atomic write + tag-diff via a single EVAL.
     *
     * TYPO3 Core's set() flow is SETEX + SMEMBERS + (sometimes) a tag-diff
     * MULTI/PIPELINE — two to three roundtrips depending on whether tag
     * membership changed. Our override collapses the whole operation
     * into a single EVAL so the data write, tag membership check, and
     * tag-set updates happen in one server-side step.
     *
     * Bench (Valkey/mTLS, phpredis 6.3): saves 60–160 µs per set() across
     * 1–20 tag counts, scaling with tag count (1 tag ≈ 1.2× faster,
     * 20 tags ≈ 1.7× faster).
     *
     * `lifetime=0` is normalised to `FAKED_UNLIMITED_LIFETIME` (one year)
     * **before** invoking Lua — bit-by-bit identical to TYPO3 Core's
     * behaviour (RedisBackend.php:254). The Lua script therefore always
     * receives a positive TTL and uses SETEX, never SET-without-TTL.
     */
    public function set(string $entryIdentifier, string $data, array $tags = [], ?int $lifetime = null): void
    {
        $lifetime ??= $this->defaultLifetime;
        if ($lifetime < 0) {
            throw new \InvalidArgumentException('The specified lifetime "' . $lifetime . '" must be greater or equal than zero.', 1279487573);
        }
        if (!$this->connected) {
            return;
        }
        if ($this->compression) {
            $data = gzcompress($data, $this->compressionLevel);
        }
        $expiration = 0 === $lifetime ? self::FAKED_UNLIMITED_LIFETIME : $lifetime;

        // Build a fully-prefixed tag-key prefix so Lua can synthesise the
        // tag→identifier set keys (keyPrefix + 'tagIdents:' + tagname)
        // without re-implementing getTagIdentifier() in Lua.
        $tagKeyPrefix = $this->keyPrefix . self::TAG_IDENTIFIERS_PREFIX;

        $argv = array_merge(
            [$expiration, $data, $entryIdentifier, $tagKeyPrefix],
            array_values($tags),
        );

        $this->redis->eval(
            self::SET_TAG_DIFF_SCRIPT,
            array_merge(
                [$this->getDataIdentifier($entryIdentifier), $this->getTagsIdentifier($entryIdentifier)],
                $argv,
            ),
            2,
        );
    }

    /**
     * Override TYPO3 Core's `flush()`:
     * - Replaces `KEYS prefix*` with a `SCAN` loop. `KEYS` blocks the
     *   Redis event loop server-side for every other client while it
     *   walks the keyspace — at millions of keys that is hundreds of ms
     *   of stop-the-world.
     * - Replaces `DEL` with `UNLINK`. UNLINK marks the keys as gone and
     *   reclaims memory in a background thread, so the caller's pipeline
     *   does not wait for value teardown.
     *
     * For the no-prefix case we keep `FLUSHDB` — single command, atomic,
     * already non-blocking-by-design.
     */
    public function flush(): void
    {
        if (!$this->connected) {
            return;
        }
        if ('' === $this->keyPrefix) {
            $this->redis->flushDB();

            return;
        }
        $this->unlinkByPattern($this->keyPrefix . '*');
    }

    /**
     * Override TYPO3 Core's `flushByTags()` so we issue **one** pipeline
     * for all tags instead of replaying the full flushByTag() flow N
     * times. We also UNLINK instead of DEL for non-blocking server-side
     * cleanup.
     */
    public function flushByTags(array $tags): void
    {
        if (!$this->connected || [] === $tags) {
            return;
        }
        $tagIdentifiers = array_map($this->getTagIdentifier(...), $tags);
        $identifierSets = $this->redis->sUnion(...$tagIdentifiers);
        if (!is_array($identifierSets) || [] === $identifierSets) {
            // No entries reference these tags — only the tag sets need
            // to disappear.
            $this->redis->unlink(...$tagIdentifiers);

            return;
        }
        $this->removeIdentifierEntriesAndRelationsBatched($identifierSets, $tags);
    }

    /**
     * Override TYPO3 Core's `flushByTag()` to route through the same
     * batched pipeline path as `flushByTags([$tag])`. Keeps a single
     * implementation, single optimisation surface.
     */
    public function flushByTag(string $tag): void
    {
        $this->flushByTags([$tag]);
    }

    /**
     * Override TYPO3 Core's `collectGarbage()`. Same root cause as
     * `flush()`: Core uses `KEYS` which blocks the server. SCAN-loop
     * instead, plus UNLINK for cleanup.
     *
     * The N×EXISTS probe per orphan candidate is left sequential — it is
     * a scheduler-side workload and pipelining the entire keyspace would
     * mean buffering one EXISTS-result per cache entry in PHP memory.
     */
    public function collectGarbage(): void
    {
        if (!$this->connected) {
            return;
        }
        $pattern = $this->getTagsIdentifier('*');
        $tagsPrefixLength = strlen($this->keyPrefix . self::IDENTIFIER_TAGS_PREFIX);
        foreach ($this->scanIterator($pattern) as $identifierToTagsKey) {
            // The key shape is `<keyPrefix>identTags:<identifier>`. Stripping
            // the known prefix is more robust than searching with strpos —
            // strpos can theoretically return false on a malformed entry
            // and would crash the GC loop.
            $identifier = substr($identifierToTagsKey, $tagsPrefixLength);
            if ($this->redis->exists($this->getDataIdentifier($identifier))) {
                continue;
            }
            $tagsToRemoveIdentifierFrom = $this->redis->sMembers($identifierToTagsKey);
            $queue = $this->redis->multi(\Redis::PIPELINE);
            $queue->unlink($identifierToTagsKey);
            foreach ($tagsToRemoveIdentifierFrom as $tag) {
                $queue->srem($this->getTagIdentifier($tag), $identifier);
            }
            $queue->exec();
        }
    }

    /**
     * SCAN+UNLINK helper used by flush(). UNLINK is called per SCAN page
     * (max 500 keys at a time) so the argument list is bounded.
     */
    private function unlinkByPattern(string $pattern): void
    {
        foreach ($this->scanChunks($pattern, 500) as $batch) {
            if ([] !== $batch) {
                $this->redis->unlink(...$batch);
            }
        }
    }

    /**
     * Pipeline-friendly cleanup for flushByTag/flushByTags. Matches the
     * shape of TYPO3 Core's `removeIdentifierEntriesAndRelations()` —
     * same Redis-set algebra (sUnion + sDiffStore + DEL) — but issues
     * `UNLINK` for the final cleanup so the server-side memory teardown
     * happens asynchronously.
     *
     * @param array<int, string> $identifiers cache-entry identifiers to flush
     * @param array<int, string> $tags        tags whose sets disappear entirely
     */
    private function removeIdentifierEntriesAndRelationsBatched(array $identifiers, array $tags): void
    {
        $uniqueTempKey = 'temp:' . StringUtility::getUniqueId();
        $prefixedKeysToDelete = [$uniqueTempKey];
        $prefixedIdentifierToTagsKeysToDelete = [];
        foreach ($identifiers as $identifier) {
            $prefixedKeysToDelete[] = $this->getDataIdentifier($identifier);
            $prefixedIdentifierToTagsKeysToDelete[] = $this->getTagsIdentifier($identifier);
        }
        foreach ($tags as $tag) {
            $prefixedKeysToDelete[] = $this->getTagIdentifier($tag);
        }
        $tagToIdentifiersSetsToRemoveIdentifiersFrom = $this->redis->sUnion(...$prefixedIdentifierToTagsKeysToDelete);
        $tagToIdentifiersSetsToRemoveIdentifiersFrom = array_diff(
            $tagToIdentifiersSetsToRemoveIdentifiersFrom,
            $tags,
        );

        $queue = $this->redis->multi(\Redis::PIPELINE);
        foreach ($identifiers as $identifier) {
            $queue->sAdd($uniqueTempKey, $identifier);
        }
        foreach ($tagToIdentifiersSetsToRemoveIdentifiersFrom as $tagToIdentifiersSet) {
            $queue->sDiffStore(
                $this->getTagIdentifier($tagToIdentifiersSet),
                $this->getTagIdentifier($tagToIdentifiersSet),
                $uniqueTempKey,
            );
        }
        // UNLINK accepts variadic keys; UNLINK is non-blocking server-side.
        $queue->unlink(...array_merge($prefixedKeysToDelete, $prefixedIdentifierToTagsKeysToDelete));
        $queue->exec();
    }

    /**
     * Yield each matching key one at a time via SCAN. Used by GC, which
     * processes keys individually.
     *
     * @return \Generator<int, string>
     */
    private function scanIterator(string $pattern, int $chunk = 100): \Generator
    {
        $cursor = null;
        do {
            $keys = $this->redis->scan($cursor, $pattern, $chunk);
            if (false === $keys) {
                continue;
            }
            foreach ($keys as $key) {
                yield $key;
            }
        } while (0 !== (int) $cursor);
    }

    /**
     * Yield matching keys as chunked arrays via SCAN. Used by flush
     * paths that benefit from batched UNLINK.
     *
     * @return \Generator<int, array<int, string>>
     */
    private function scanChunks(string $pattern, int $chunk = 500): \Generator
    {
        $cursor = null;
        do {
            $keys = $this->redis->scan($cursor, $pattern, $chunk);
            if (false === $keys || [] === $keys) {
                continue;
            }
            yield $keys;
        } while (0 !== (int) $cursor);
    }

    /**
     * Map RedisBackend's TYPO3-style properties plus rawOptions into the
     * KeyValueConnectionFactory option schema.
     *
     * Standard options (hostname, port, database, password, …) are read from
     * the RedisBackend properties set by parent::__construct() via setters.
     * TLS, Sentinel, backoff and any other extra keys come from $this->rawOptions
     * and are merged on top, overriding the defaults where needed.
     */
    private function buildFactoryOptions(): array
    {
        $opts = [
            'host' => $this->hostname,
            'port' => $this->port,
            // Read the configured value, not core's copy of it: core stores whole
            // seconds, so a deliberate 2.5 would arrive here as 3. The socket can
            // honour the fraction even though core's property cannot.
            'connectTimeout' => (float) ($this->rawOptions['connectTimeout']
                ?? $this->rawOptions['connectionTimeout']
                ?? $this->connectionTimeout),
            'readTimeout' => (float) ($this->rawOptions['readTimeout'] ?? $this->rawOptions['read_timeout'] ?? 0.0),
            'retryInterval' => (int) ($this->rawOptions['retryInterval'] ?? $this->rawOptions['retry_interval'] ?? 0),
            'database' => $this->database,
            // Persistent: use the database-specific ID so that different databases
            // get distinct persistent connections.
            'persistent' => $this->persistentConnection
                ? ('typo3-cache-' . (string) $this->database)
                : false,
            // Defer the TCP/TLS handshake to the first command. TYPO3 boots
            // every configured cache backend eagerly, but most requests only
            // touch a handful of them — paying 11× ping-roundtrips at
            // bootstrap (and the full TLS handshake on the first warm
            // request after a worker reload) is pure overhead. phpredis
            // applies AUTH/SELECT on first connect, so correctness is
            // unaffected; only the timing of the network IO moves.
            'lazy' => true,
        ];

        // An empty password means "none configured", not "authenticate with the
        // empty string". Core disagrees — setPassword('') stores '' rather than
        // null, so getAuthentication() hands back '' and core would send
        // AUTH ''. Against a server without requirepass that is refused, which
        // turns an unset environment variable into a dead cache connection
        // instead of a working one. The distinction matters here because the
        // options are assembled from site configuration, where an unresolved
        // placeholder arrives as exactly that empty string.
        $authentication = $this->getAuthentication();
        if (null !== $authentication && '' !== $authentication) {
            $opts['auth'] = $authentication;
        }

        // Merge all raw options (TLS, sentinel, backoff, and any extra keys).
        // rawOptions may also contain standard keys like hostname/port that were
        // already applied via parent setters — they are harmless extras for the
        // factory, except that they arrive as configuration strings and would
        // overwrite the typed values assembled above. Type the result, not the
        // inputs, so every path into this method ends up the same shape.
        $merged = array_replace($opts, $this->rawOptions);

        foreach (['port', 'database', 'retryInterval'] as $key) {
            if (isset($merged[$key]) && is_numeric($merged[$key])) {
                $merged[$key] = (int) $merged[$key];
            }
        }

        foreach (['connectTimeout', 'readTimeout'] as $key) {
            if (isset($merged[$key]) && is_numeric($merged[$key])) {
                $merged[$key] = (float) $merged[$key];
            }
        }

        return $merged;
    }
}
