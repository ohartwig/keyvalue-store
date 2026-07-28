<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Connection;

use Moselwal\KeyValueStore\Connection\ValueObject\ConnectionParams;
use Moselwal\KeyValueStore\Connection\ValueObject\Endpoint;

final class KeyValueConnectionFactory implements ConnectionFactoryInterface
{
    public function __construct(
        private readonly TlsContextBuilder $tlsContextBuilder = new TlsContextBuilder(),
        private readonly SentinelResolver $sentinelResolver = new SentinelResolver(),
    ) {}

    /**
     * Create a configured Redis connection using the phpredis >= 6.0 constructor API.
     *
     * All connection parameters use the phpredis camelCase constructor keys.
     * Snake_case aliases are accepted for backward compatibility.
     *
     * Connection options:
     *   host            string   hostname, IP, or unix-socket path
     *   port            int      default 6379; -1 or 0 for unix socket
     *   connectTimeout  float    seconds, 0 = unlimited (alias: timeout)
     *   readTimeout     float    seconds, 0 = unlimited (alias: read_timeout)
     *   retryInterval   int      milliseconds between retries (alias: retry_interval)
     *   database        int      Redis database index, default 0
     *   auth            mixed    phpredis auth: string (password), ['user','pass'] (ACL)
     *   password        string   legacy — combined with 'username' into auth
     *   username        string   legacy ACL username
     *   persistent      mixed    string = persistent ID, true = auto-ID, false = off
     *   persistent_id   string   legacy persistent ID alias
     *   backoff         array    phpredis backoff config:
     *                            ['algorithm' => Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER,
     *                             'base' => 500, 'cap' => 750]
     *
     * TLS options (handled by TlsContextBuilder):
     *   tls             bool     enable TLS
     *   ca_file         string   CA certificate path
     *   cert_file       string   client certificate path (mTLS)
     *   key_file        string   client private key path (mTLS)
     *   peer_name       string   SNI / expected peer name
     *   verify_peer     bool     default true
     *   verify_peer_name bool    default true
     *   allow_self_signed bool   default false
     *
     * Sentinel options:
     *   sentinel          bool   enable Sentinel master resolution
     *   sentinel_host     string Sentinel hostname
     *   sentinel_port     int    Sentinel port, default 26379
     *   sentinel_service  string master name (e.g. 'mymaster')
     *   sentinel_password string optional Sentinel AUTH password
     *
     * Behaviour:
     *   lazy  bool  If true, the connection is deferred until the first command
     *               (phpredis lazy-connect). If false (default), ping() is called
     *               immediately to verify the connection and surface errors early.
     *               Auth and database selection are always embedded in the
     *               constructor config and applied automatically by phpredis.
     */
    public function create(array $options): \Redis
    {
        $params = ConnectionParams::fromOptions($options);
        $tlsContext = $this->tlsContextBuilder->build($options);
        $endpoint = $this->resolveEndpoint($options, $params->connectTimeout);
        $lazy = (bool) ($options['lazy'] ?? false);

        $redis = $this->buildRedis($endpoint, $tlsContext, $params);

        if (!$lazy) {
            // Force an immediate connection and surface any connectivity or auth errors.
            // phpredis applies AUTH and SELECT automatically before executing ping().
            $redis->ping();
        }

        return $redis;
    }

    // -------------------------------------------------------------------------
    // Private: endpoint resolution
    // -------------------------------------------------------------------------

    private function resolveEndpoint(array $options, float $connectTimeout): Endpoint
    {
        if (isset($options['sentinel']) && true === (bool) $options['sentinel']) {
            return $this->sentinelResolver->resolveMaster([
                'sentinel' => true,
                'sentinel_host' => (string) ($options['sentinel_host'] ?? ''),
                'sentinel_port' => (int) ($options['sentinel_port'] ?? 26379),
                'sentinel_service' => (string) ($options['sentinel_service'] ?? ''),
                'sentinel_password' => $options['sentinel_password'] ?? null,
                'connectTimeout' => $connectTimeout,
                'persistent_id' => $options['sentinel_persistent_id'] ?? null,
                // Forward TLS options so the sentinel connection itself can be TLS-encrypted.
                'tls' => $options['tls'] ?? false,
                'ca_file' => $options['ca_file'] ?? '',
                'cert_file' => $options['cert_file'] ?? '',
                'key_file' => $options['key_file'] ?? '',
                'peer_name' => $options['peer_name'] ?? '',
                'verify_peer' => $options['verify_peer'] ?? true,
                'verify_peer_name' => $options['verify_peer_name'] ?? true,
                'allow_self_signed' => $options['allow_self_signed'] ?? false,
            ]);
        }

        $host = (string) ($options['host'] ?? '');
        if ('' === $host) {
            throw new \InvalidArgumentException('Redis host must be set.');
        }

        return new Endpoint($host, (int) ($options['port'] ?? 6379), $connectTimeout);
    }

    // -------------------------------------------------------------------------
    // Private: Redis instance creation (phpredis >= 6.0 constructor)
    // -------------------------------------------------------------------------

    /**
     * Build a Redis instance using the phpredis >= 6.0 constructor config array.
     *
     * The returned instance uses lazy connect: the TCP/TLS connection is deferred
     * until the first command. Auth and database selection are embedded in the
     * config and applied automatically by phpredis on first connect.
     *
     * Call ping() on the returned instance to force an immediate connection.
     *
     * phpredis constructor keys (all camelCase):
     *   host           string
     *   port           int
     *   connectTimeout float   seconds
     *   readTimeout    float   seconds
     *   retryInterval  int     milliseconds
     *   database       int
     *   auth           mixed   string | ['password'] | ['user', 'password']
     *   persistent     mixed   string (ID) | bool
     *   ssl            array   PHP stream SSL context options (without the outer 'ssl' wrapper)
     *   backoff        array   ['algorithm' => ..., 'base' => ..., 'cap' => ...]
     */
    private function buildRedis(Endpoint $endpoint, ?array $tlsContext, ConnectionParams $params): \Redis
    {
        return new \Redis($this->buildRedisConfig($endpoint, $tlsContext, $params));
    }

    /**
     * Assembles the phpredis constructor config.
     *
     * Separate from buildRedis() because \Redis connects inside its own
     * constructor: anything that wants to check what we are about to ask for —
     * the tls:// prefix, the auth entry, the backoff block — otherwise has to
     * open a socket to find out. The two tests covering this were doing exactly
     * that and depended on a host named redis.local resolving.
     *
     * @param array{ssl: array<string, mixed>}|null $tlsContext
     *
     * @return array<string, mixed>
     */
    private function buildRedisConfig(Endpoint $endpoint, ?array $tlsContext, ConnectionParams $params): array
    {
        $cfg = [
            'host' => null !== $tlsContext ? ('tls://' . $endpoint->host) : $endpoint->host,
            'port' => $endpoint->port,
            'connectTimeout' => $params->connectTimeout,
            'readTimeout' => $params->readTimeout,
            'retryInterval' => $params->retryInterval,
            'database' => $params->database,
        ];

        if (null !== $params->auth) {
            $cfg['auth'] = $params->auth;
        }

        if (false !== $params->persistent) {
            $cfg['persistent'] = $params->persistent; // string ID or true
        }

        if (null !== $tlsContext) {
            // Constructor expects the SSL options directly (no outer 'ssl' wrapper).
            $cfg['ssl'] = $tlsContext['ssl'];
        }

        if (null !== $params->backoff) {
            $cfg['backoff'] = $params->backoff;
        }

        return $cfg;
    }
}
