<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Functional\Connection;

use Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend;
use Moselwal\KeyValueStore\Connection\ConnectionReuse;
use Moselwal\KeyValueStore\Connection\KeyValueConnectionFactory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * KEYVALUE_CONNECTION_REUSE against a real server: what a FrankenPHP worker's
 * later request gets from the factory, and what the server sees.
 *
 * Requires a running Redis-protocol server (REDIS_HOST / REDIS_PORT).
 */
#[RequiresPhpExtension('redis')]
final class ConnectionReuseFunctionalTest extends TestCase
{
    private string $host;

    private int $port;

    protected function setUp(): void
    {
        $this->host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $this->port = (int) (getenv('REDIS_PORT') ?: 6379);

        try {
            $redis = new \Redis();
            $redis->connect($this->host, $this->port, 1.0);
            $redis->ping();
            $redis->close();
        } catch (\RedisException) {
            self::markTestSkipped('Redis is not available at ' . $this->host . ':' . $this->port);
        }

        $_SERVER['FRANKENPHP_WORKER'] = '1';
        $_SERVER[ConnectionReuse::ENV] = '1';
        ConnectionReuse::reset();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['FRANKENPHP_WORKER'], $_SERVER[ConnectionReuse::ENV]);
        ConnectionReuse::reset();
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function options(array $extra = []): array
    {
        return $extra + ['host' => $this->host, 'port' => $this->port, 'database' => 14, 'lazy' => true];
    }

    #[Test]
    public function aLaterRequestGetsTheSameConnection(): void
    {
        $factory = new KeyValueConnectionFactory();
        $first = $factory->create($this->options());
        $first->set('reuse-probe', '1');
        $clientId = $first->client('id');

        $second = (new KeyValueConnectionFactory())->create($this->options());

        self::assertSame($first, $second);
        self::assertSame($clientId, $second->client('id'), 'the server sees the same connection');
        self::assertSame('1', $second->get('reuse-probe'));
        $second->del('reuse-probe');
    }

    #[Test]
    public function differentOptionSetsNeverShareAConnection(): void
    {
        $factory = new KeyValueConnectionFactory();
        $database14 = $factory->create($this->options());
        $database15 = $factory->create($this->options(['database' => 15]));
        $withSerializer = $factory->create($this->options(['serializer' => 'php']));

        self::assertNotSame($database14, $database15);
        self::assertNotSame($database14, $withSerializer);

        $database14->set('reuse-isolation', 'fourteen');
        self::assertFalse($database15->get('reuse-isolation'));
        self::assertSame($database14, $factory->create($this->options()));
        self::assertSame($database15, $factory->create($this->options(['database' => 15])));
        $database14->del('reuse-isolation');
    }

    #[Test]
    public function withTheSwitchOffEveryCreateBuildsANewConnection(): void
    {
        $_SERVER[ConnectionReuse::ENV] = '0';
        $factory = new KeyValueConnectionFactory();

        self::assertNotSame($factory->create($this->options()), $factory->create($this->options()));
        self::assertSame(0, ConnectionReuse::count());
    }

    #[Test]
    public function aConnectionLeftInAPipelineIsNotReused(): void
    {
        $factory = new KeyValueConnectionFactory();
        $first = $factory->create($this->options());
        $first->multi(\Redis::PIPELINE);
        $first->set('reuse-pipeline', '1');

        $second = $factory->create($this->options());

        self::assertNotSame($first, $second);
        self::assertSame(\Redis::ATOMIC, $second->getMode());
        $first->discard();
    }

    #[Test]
    public function aConnectionLeftInATransactionIsNotReused(): void
    {
        $factory = new KeyValueConnectionFactory();
        $first = $factory->create($this->options());
        $first->multi();

        self::assertNotSame($first, $factory->create($this->options()));
        $first->discard();
    }

    #[Test]
    public function aConnectionMovedToAnotherDatabaseIsNotReused(): void
    {
        $factory = new KeyValueConnectionFactory();
        $first = $factory->create($this->options());
        $first->select(13);

        $second = $factory->create($this->options());

        self::assertNotSame($first, $second);
        self::assertSame(14, $second->getDBNum());
    }

    #[Test]
    public function aConnectionWithAServerErrorIsNotReused(): void
    {
        $factory = new KeyValueConnectionFactory();
        $first = $factory->create($this->options());
        $first->set('reuse-wrongtype', 'string');
        try {
            $first->lPush('reuse-wrongtype', 'x');
        } catch (\RedisException) {
            // phpredis may throw or return false; either way the error is recorded.
        }
        self::assertNotNull($first->getLastError());

        self::assertNotSame($first, $factory->create($this->options()));
        $first->clearLastError();
        $first->del('reuse-wrongtype');
    }

    #[Test]
    public function aForgottenConnectionIsNotReused(): void
    {
        $factory = new KeyValueConnectionFactory();
        $first = $factory->create($this->options());
        ConnectionReuse::forget($first);

        self::assertNotSame($first, $factory->create($this->options()));
    }

    #[Test]
    public function clientOptionsAreBackToThoseOfANewConnection(): void
    {
        $factory = new KeyValueConnectionFactory();
        $first = $factory->create($this->options());
        $fresh = [
            \Redis::OPT_SERIALIZER => $first->getOption(\Redis::OPT_SERIALIZER),
            \Redis::OPT_PREFIX => $first->getOption(\Redis::OPT_PREFIX),
            \Redis::OPT_SCAN => $first->getOption(\Redis::OPT_SCAN),
        ];
        $first->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $first->setOption(\Redis::OPT_PREFIX, 'leaked:');
        $first->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);

        $second = $factory->create($this->options());

        self::assertSame($first, $second);
        foreach ($fresh as $option => $value) {
            self::assertSame($value, $second->getOption($option), 'option ' . $option);
        }
        $second->set('reuse-prefix', 'plain');
        self::assertSame(1, $second->exists('reuse-prefix'));
        $second->del('reuse-prefix');
    }

    #[Test]
    public function aConnectionTheServerClosedReconnectsOnItsNextCommand(): void
    {
        $factory = new KeyValueConnectionFactory();
        $first = $factory->create($this->options());
        $first->set('reuse-reconnect', '1');
        $killer = new \Redis();
        $killer->connect($this->host, $this->port, 1.0);
        $killer->client('kill', 'id', (string) $first->client('id'));
        $killer->close();

        $second = $factory->create($this->options());

        self::assertSame('1', $second->get('reuse-reconnect'));
        self::assertSame(14, $second->getDBNum());
        $second->del('reuse-reconnect');
    }

    #[Test]
    public function cacheBackendsOfLaterRequestsShareTheirConnectionAndKeepTheirSerializer(): void
    {
        $plain = $this->backend('reuse_plain', []);
        $plainAgain = $this->backend('reuse_plain', []);
        $php = $this->backend('reuse_php', ['serializer' => 'php']);

        self::assertSame($this->redisOf($plain), $this->redisOf($plainAgain));
        self::assertNotSame($this->redisOf($plain), $this->redisOf($php));
        self::assertSame(\Redis::SERIALIZER_NONE, $this->redisOf($plainAgain)->getOption(\Redis::OPT_SERIALIZER));
        self::assertSame(\Redis::SERIALIZER_PHP, $this->redisOf($php)->getOption(\Redis::OPT_SERIALIZER));

        $plain->set('entry', 'value');
        self::assertSame('value', $plainAgain->get('entry'));
        $plain->flush();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function backend(string $identifier, array $options): KeyValueBackend
    {
        $backend = new KeyValueBackend($options + ['hostname' => $this->host, 'port' => $this->port, 'database' => 15]);
        $backend->setCache(new VariableFrontend($identifier, $backend));
        $backend->initializeObject();

        return $backend;
    }

    private function redisOf(KeyValueBackend $backend): \Redis
    {
        $redis = (new \ReflectionProperty($backend, 'redis'))->getValue($backend);
        self::assertInstanceOf(\Redis::class, $redis);

        return $redis;
    }
}
