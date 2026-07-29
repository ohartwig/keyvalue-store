<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Connection;

use Moselwal\KeyValueStore\Connection\KeyValueConnectionFactory;
use Moselwal\KeyValueStore\Connection\SentinelResolver;
use Moselwal\KeyValueStore\Connection\TlsContextBuilder;
use Moselwal\KeyValueStore\Connection\ValueObject\ConnectionParams;
use Moselwal\KeyValueStore\Connection\ValueObject\Endpoint;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for KeyValueConnectionFactory.
 *
 * Since TlsContextBuilder and SentinelResolver are final classes, we use
 * real instances and Reflection to test internal behavior.
 */
final class KeyValueConnectionFactoryTest extends TestCase
{
    #[Test]
    public function testConstructorAcceptsDependencies(): void
    {
        $factory = new KeyValueConnectionFactory(
            new TlsContextBuilder(),
            new SentinelResolver(),
        );

        self::assertInstanceOf(KeyValueConnectionFactory::class, $factory);
    }

    #[Test]
    public function testCreateThrowsWhenHostIsEmpty(): void
    {
        $factory = new KeyValueConnectionFactory();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Redis host must be set.');

        $factory->create(['host' => '', 'lazy' => true]);
    }

    #[Test]
    public function testResolveEndpointCreatesDirect(): void
    {
        $factory = new KeyValueConnectionFactory();

        $method = new \ReflectionMethod($factory, 'resolveEndpoint');

        $endpoint = $method->invoke($factory, ['host' => '10.0.0.1', 'port' => 6380], 2.5);

        self::assertInstanceOf(Endpoint::class, $endpoint);
        self::assertSame('10.0.0.1', $endpoint->host);
        self::assertSame(6380, $endpoint->port);
        self::assertSame(2.5, $endpoint->timeout);
    }

    #[Test]
    public function testResolveEndpointUsesDefaultPort(): void
    {
        $factory = new KeyValueConnectionFactory();

        $method = new \ReflectionMethod($factory, 'resolveEndpoint');

        $endpoint = $method->invoke($factory, ['host' => 'redis.local'], 1.0);

        self::assertSame(6379, $endpoint->port);
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function testBuildRedisConfigIncludesTlsPrefix(): void
    {
        $factory = new KeyValueConnectionFactory();

        $method = new \ReflectionMethod($factory, 'buildRedisConfig');

        $endpoint = new Endpoint('redis.local', 6379, 1.0);
        $tlsContext = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]];
        $params = ConnectionParams::fromOptions([]);

        $cfg = $method->invoke($factory, $endpoint, $tlsContext, $params);

        self::assertSame('tls://redis.local', $cfg['host']);
        self::assertSame($tlsContext['ssl'], $cfg['ssl']);
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function testBuildRedisConfigOmitsTlsPrefixWithoutContext(): void
    {
        $factory = new KeyValueConnectionFactory();

        $method = new \ReflectionMethod($factory, 'buildRedisConfig');

        $endpoint = new Endpoint('redis.local', 6379, 1.0);
        $params = ConnectionParams::fromOptions([]);

        $cfg = $method->invoke($factory, $endpoint, null, $params);

        self::assertSame('redis.local', $cfg['host']);
        self::assertArrayNotHasKey('ssl', $cfg);
    }

    /**
     * The sentinel branch of resolveEndpoint() translates the factory's own
     * option names into the ones SentinelResolver expects — sentinel_persistent_id
     * becomes persistent_id, and the TLS options are forwarded so the connection
     * to the Sentinel itself can be encrypted.
     *
     * That mapping had no test because SentinelResolver is final and cannot be
     * doubled. Pointing it at a closed port exercises the whole branch anyway:
     * every option is evaluated before the resolver ever opens a socket, and the
     * connection then fails immediately rather than waiting for a timeout.
     */
    #[Test]
    #[RequiresPhpExtension('redis')]
    public function testCreateResolvesThroughSentinelWhenEnabled(): void
    {
        $factory = new KeyValueConnectionFactory();

        $this->expectException(\RedisException::class);

        $factory->create([
            'sentinel' => true,
            'sentinel_host' => '127.0.0.1',
            'sentinel_port' => 59999,
            'sentinel_service' => 'mymaster',
            'sentinel_password' => 's3cret',
            'sentinel_persistent_id' => 'sentinel-1',
            'connectTimeout' => 0.05,
            'tls' => true,
            'verify_peer' => false,
        ]);
    }

    /**
     * Without the sentinel flag the direct endpoint is used, so an unset host
     * has to be rejected rather than silently resolved through Sentinel.
     */
    #[Test]
    public function testCreateIgnoresSentinelOptionsWhenSentinelIsDisabled(): void
    {
        $factory = new KeyValueConnectionFactory();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Redis host must be set.');

        $factory->create([
            'sentinel' => false,
            'sentinel_host' => '127.0.0.1',
            'sentinel_service' => 'mymaster',
        ]);
    }
}
