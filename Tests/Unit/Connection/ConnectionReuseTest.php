<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Connection;

use Moselwal\KeyValueStore\Connection\ConnectionReuse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which option sets ConnectionReuse keeps, and under which key. No server
 * involved; ConnectionReuseFunctionalTest covers the connections themselves.
 */
final class ConnectionReuseTest extends TestCase
{
    private const OPTIONS = ['host' => 'valkey', 'port' => 6379, 'database' => 3, 'auth' => 'secret', 'lazy' => true];

    protected function setUp(): void
    {
        $_SERVER['FRANKENPHP_WORKER'] = '1';
        $_SERVER[ConnectionReuse::ENV] = '1';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['FRANKENPHP_WORKER'], $_SERVER[ConnectionReuse::ENV]);
        ConnectionReuse::reset();
    }

    #[Test]
    public function theSameOptionsHaveTheSameKey(): void
    {
        self::assertNotNull(ConnectionReuse::key(self::OPTIONS));
        self::assertSame(ConnectionReuse::key(self::OPTIONS), ConnectionReuse::key(self::OPTIONS));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function differentOptionSets(): iterable
    {
        yield 'host' => [['host' => 'other'] + self::OPTIONS];
        yield 'port' => [['port' => 6380] + self::OPTIONS];
        yield 'database' => [['database' => 4] + self::OPTIONS];
        yield 'password' => [['auth' => 'other'] + self::OPTIONS];
        yield 'ACL user' => [['auth' => ['user', 'secret']] + self::OPTIONS];
        yield 'TLS' => [self::OPTIONS + ['tls' => true, 'ca_file' => '/ca.pem']];
        yield 'serializer' => [self::OPTIONS + ['serializer' => 'igbinary']];
        yield 'compression' => [self::OPTIONS + ['compression' => true]];
        yield 'key prefix' => [self::OPTIONS + ['keyPrefix' => 'pages:']];
        yield 'read timeout' => [self::OPTIONS + ['readTimeout' => 2.0]];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('differentOptionSets')]
    public function anyDifferenceIsAnotherKey(array $options): void
    {
        self::assertNotNull(ConnectionReuse::key($options));
        self::assertNotSame(ConnectionReuse::key(self::OPTIONS), ConnectionReuse::key($options));
    }

    #[Test]
    public function nothingIsKeptWithTheSwitchOff(): void
    {
        $_SERVER[ConnectionReuse::ENV] = '0';
        self::assertNull(ConnectionReuse::key(self::OPTIONS));

        unset($_SERVER[ConnectionReuse::ENV]);
        self::assertNull(ConnectionReuse::key(self::OPTIONS));
    }

    #[Test]
    public function nothingIsKeptOutsideAFrankenPhpWorker(): void
    {
        unset($_SERVER['FRANKENPHP_WORKER']);
        self::assertNull(ConnectionReuse::key(self::OPTIONS));
    }

    #[Test]
    public function aConnectionTheCallerWantsVerifiedIsNotKept(): void
    {
        self::assertNull(ConnectionReuse::key(['lazy' => false] + self::OPTIONS));
    }

    #[Test]
    public function aSentinelOptionSetIsNotKept(): void
    {
        self::assertNull(ConnectionReuse::key(self::OPTIONS + ['sentinel' => true, 'sentinel_host' => 'sentinel']));
    }
}
