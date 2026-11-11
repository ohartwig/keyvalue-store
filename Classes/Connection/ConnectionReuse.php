<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Connection;

/**
 * Keeps phpredis connections for the life of a FrankenPHP worker
 * (KEYVALUE_CONNECTION_REUSE=1), so a request does not open, authenticate and
 * select its database again for every cache, session and lock backend.
 *
 * Each \Redis object belongs to exactly one complete option set: the array
 * the factory was given, which is everything the backend asked for (host,
 * port, database, credentials, TLS, timeouts, and the backend's own options
 * such as serializer, compression and key prefix, which the cache backend
 * merges into it). Two option sets that differ in anything get two objects;
 * a hash of the serialised array is the key.
 *
 * Before an object is handed out again it must still be what a new one would
 * be. It is dropped, and a new one built, when it is not in ATOMIC mode (a
 * request ended inside MULTI or a pipeline), when it is on another database
 * than its options say (someone sent SELECT), when the server reported an
 * error on it, or when a backend reported a connection error on it (forget()).
 * Client options a backend sets after create() (serializer, prefix, SCAN
 * mode, ...) are put back to the values the object had when it was built, so
 * the backend sets them again exactly as on a new object.
 *
 * A connection the server closed in the meantime needs nothing from here:
 * phpredis reconnects on the next command, applying AUTH and SELECT from its
 * constructor configuration again.
 *
 * Not kept: option sets with Sentinel (the master is resolved per create(),
 * and must stay so), option sets without `lazy` (their caller wants the
 * connection verified with a PING on create()), and anything outside a
 * FrankenPHP worker, where a request ends with its process anyway.
 *
 * @internal
 */
final class ConnectionReuse
{
    public const ENV = 'KEYVALUE_CONNECTION_REUSE';

    /**
     * phpredis client options a backend may set after create(); restored on
     * every reuse. Names, because not every phpredis build defines all.
     */
    private const CLIENT_OPTIONS = [
        'OPT_SERIALIZER',
        'OPT_PREFIX',
        'OPT_SCAN',
        'OPT_COMPRESSION',
        'OPT_COMPRESSION_LEVEL',
        'OPT_READ_TIMEOUT',
        'OPT_REPLY_LITERAL',
        'OPT_NULL_MULTIBULK_AS_NULL',
        'OPT_MAX_RETRIES',
        'OPT_TCP_KEEPALIVE',
    ];

    /** @var array<string, array{redis: \Redis, database: int, options: array<int, mixed>}> */
    private static array $kept = [];

    /**
     * The key of $options, or null when this option set is not kept.
     *
     * @param array<string, mixed> $options as given to the factory
     */
    public static function key(array $options): ?string
    {
        if (!self::isEnabled()
            || true !== (bool) ($options['lazy'] ?? false)
            || true === (bool) ($options['sentinel'] ?? false)
        ) {
            return null;
        }

        return hash('xxh128', serialize($options));
    }

    /** The kept object for $key if it can serve another request, otherwise null (and it is dropped). */
    public static function take(string $key): ?\Redis
    {
        $entry = self::$kept[$key] ?? null;
        if (null === $entry) {
            return null;
        }
        $redis = $entry['redis'];
        try {
            if (\Redis::ATOMIC === $redis->getMode()
                && $entry['database'] === $redis->getDBNum()
                && null === $redis->getLastError()
            ) {
                foreach ($entry['options'] as $option => $value) {
                    if ($redis->getOption($option) !== $value) {
                        $redis->setOption($option, $value);
                    }
                }

                return $redis;
            }
        } catch (\RedisException) {
            // Dropped below.
        }
        unset(self::$kept[$key]);

        return null;
    }

    /** Keeps a newly built object under $key, with its client options as they are now. */
    public static function keep(string $key, \Redis $redis, int $database): void
    {
        $options = [];
        foreach (self::CLIENT_OPTIONS as $name) {
            $constant = \Redis::class . '::' . $name;
            if (\defined($constant)) {
                $option = \constant($constant);
                $options[$option] = $redis->getOption($option);
            }
        }
        self::$kept[$key] = ['redis' => $redis, 'database' => $database, 'options' => $options];
    }

    /** A backend saw a connection error on $redis: never hand it out again. */
    public static function forget(\Redis $redis): void
    {
        foreach (self::$kept as $key => $entry) {
            if ($entry['redis'] === $redis) {
                unset(self::$kept[$key]);
            }
        }
    }

    /** Number of kept objects. */
    public static function count(): int
    {
        return \count(self::$kept);
    }

    /** Drops every kept object (tests). */
    public static function reset(): void
    {
        self::$kept = [];
    }

    private static function isEnabled(): bool
    {
        // FrankenPHP sets this in worker mode only.
        if (!(bool) ($_SERVER['FRANKENPHP_WORKER'] ?? false)) {
            return false;
        }
        $value = $_SERVER[self::ENV] ?? $_ENV[self::ENV] ?? getenv(self::ENV);
        if (!\is_scalar($value)) {
            return false;
        }

        return filter_var(trim((string) $value), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
