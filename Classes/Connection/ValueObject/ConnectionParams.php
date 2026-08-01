<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Connection\ValueObject;

/**
 * Immutable value object holding parsed connection parameters.
 *
 * Field names mirror the phpredis >= 6.0 constructor array exactly so that
 * createLazyRedis() can pass them through without any renaming.
 * Legacy snake_case option keys (timeout, read_timeout, retry_interval,
 * password/username, persistent_id) are accepted as aliases in fromOptions().
 */
final class ConnectionParams
{
    /**
     * @param float                             $connectTimeout phpredis: connectTimeout (seconds, 0 = unlimited)
     * @param float                             $readTimeout    phpredis: readTimeout    (seconds, 0 = unlimited)
     * @param int                               $retryInterval  phpredis: retryInterval  (milliseconds)
     * @param int                               $database       phpredis: database
     * @param string|array|null                 $auth           phpredis: auth — null (no auth), string (password),
     *                                                          ['password'] (legacy), ['user','password'] (ACL)
     * @param string|bool                       $persistent     phpredis: persistent — false (off), true (auto-ID),
     *                                                          string (explicit persistent ID)
     * @param array|null                        $backoff        phpredis: backoff — reconnection backoff config, e.g.
     *                                                          ['algorithm' => Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER,
     *                                                          'base' => 500, 'cap' => 750]
     * @param string|array{string, string}|null $auth
     * @param array<string, mixed>|null         $backoff
     */
    public function __construct(
        public readonly float $connectTimeout,
        public readonly float $readTimeout,
        public readonly int $retryInterval,
        public readonly int $database,
        public readonly string|array|null $auth,
        public readonly string|bool $persistent,
        public readonly ?array $backoff,
    ) {}

    /**
     * Parse a flat options array into a ConnectionParams instance.
     *
     * Accepted option keys (phpredis camelCase is primary; snake_case aliases are supported):
     *
     *   connectTimeout  float   seconds (alias: timeout)
     *   readTimeout     float   seconds (alias: read_timeout)
     *   retryInterval   int     milliseconds (alias: retry_interval)
     *   database        int
     *   auth            mixed   phpredis-style auth value — takes priority over username/password
     *   username        string  ACL username (combined with password into ['user','pass'])
     *   password        string  plain password or part of ACL pair
     *   persistent      mixed   phpredis-style persistent value (string|bool)
     *   persistent_id   string  legacy: treated as persistent string ID
     *   backoff         array   phpredis backoff config
     *
     * @param array<string, mixed> $options
     */
    public static function fromOptions(array $options): self
    {
        return new self(
            connectTimeout: (float) ($options['connectTimeout'] ?? $options['timeout'] ?? 1.0),
            readTimeout: (float) ($options['readTimeout'] ?? $options['read_timeout'] ?? 0.0),
            retryInterval: (int) ($options['retryInterval'] ?? $options['retry_interval'] ?? 0),
            database: (int) ($options['database'] ?? 0),
            auth: self::resolveAuth($options),
            persistent: self::resolvePersistent($options),
            backoff: isset($options['backoff']) && is_array($options['backoff'])
                ? $options['backoff']
                : null,
        );
    }

    /**
     * Resolve authentication from options.
     *
     * Priority:
     *   1. 'auth' key — passed through as-is (phpredis native)
     *   2. 'password' + optional 'username' — converted to phpredis auth format
     *   3. null — no authentication
     *
     * @param array<string, mixed> $options
     *
     * @return string|array{string, string}|null password, or [username, password] when a user is set
     */
    private static function resolveAuth(array $options): string|array|null
    {
        if (array_key_exists('auth', $options)) {
            return $options['auth']; // phpredis-native: string|array|null
        }

        $password = isset($options['password']) ? (string) $options['password'] : '';
        if ('' === $password) {
            return null;
        }

        $username = isset($options['username']) ? (string) $options['username'] : '';

        return '' !== $username ? [$username, $password] : $password;
    }

    /**
     * Resolve persistent connection setting from options.
     *
     * Priority:
     *   1. 'persistent' as string  — used directly as persistent ID
     *   2. 'persistent' as truthy  — persistent on with auto-generated ID
     *   3. 'persistent_id' (string) — legacy alias for a string persistent ID
     *   4. false                   — no persistent connection
     *
     * @param array<string, mixed> $options
     */
    private static function resolvePersistent(array $options): string|bool
    {
        if (array_key_exists('persistent', $options)) {
            $v = $options['persistent'];
            if (is_string($v) && '' !== $v) {
                return $v;  // explicit persistent ID
            }

            return (bool) $v; // true = auto-ID, false = off
        }

        // Legacy: separate persistent_id key
        if (isset($options['persistent_id']) && '' !== (string) $options['persistent_id']) {
            return (string) $options['persistent_id'];
        }

        return false;
    }
}
