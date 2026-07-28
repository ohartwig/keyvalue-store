<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Cache\Backend;

use Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend;
use Moselwal\KeyValueStore\Connection\ConnectionFactoryInterface;
use Moselwal\KeyValueStore\Connection\KeyValueConnectionFactory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for KeyValueBackend::initializeObject() and buildFactoryOptions().
 *
 * T009: initializeObject() delegates to factory->create() with correct options.
 * T010: buildFactoryOptions() maps parent properties and merges rawOptions.
 *
 * Since buildFactoryOptions() is private, we test it via Reflection.
 * Tests that instantiate KeyValueBackend require ext-redis (parent RedisBackend needs it).
 */
final class KeyValueBackendTest extends TestCase
{
    // -------------------------------------------------------------------------
    // T010: buildFactoryOptions() — standard option mapping
    // -------------------------------------------------------------------------

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function buildFactoryOptionsMapsHostnameToHost(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => 'redis.example.com',
            'port' => 6380,
            'database' => 3,
        ]);

        $opts = $this->invokeBuildFactoryOptions($backend);

        self::assertSame('redis.example.com', $opts['host']);
        self::assertSame(6380, $opts['port']);
        self::assertSame(3, $opts['database']);
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function buildFactoryOptionsUsesDefaultValues(): void
    {
        $backend = new KeyValueBackend([]);

        $opts = $this->invokeBuildFactoryOptions($backend);

        // RedisBackend defaults
        self::assertSame('127.0.0.1', $opts['host']);
        self::assertSame(6379, $opts['port']);
        self::assertSame(0, $opts['database']);
        self::assertSame(0.0, $opts['connectTimeout']);
        self::assertSame(0.0, $opts['readTimeout']);
        self::assertSame(0, $opts['retryInterval']);
        self::assertFalse($opts['persistent']);
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function buildFactoryOptionsCastsTypesCorrectly(): void
    {
        $backend = new KeyValueBackend([
            'port' => '6380',
            'database' => '5',
            'connectionTimeout' => '2.5',
        ]);

        $opts = $this->invokeBuildFactoryOptions($backend);

        self::assertIsInt($opts['port']);
        self::assertSame(6380, $opts['port']);
        self::assertIsInt($opts['database']);
        self::assertSame(5, $opts['database']);
        self::assertIsFloat($opts['connectTimeout']);
        self::assertSame(2.5, $opts['connectTimeout']);
    }

    // -------------------------------------------------------------------------
    // T010: buildFactoryOptions() — password / auth handling
    // -------------------------------------------------------------------------

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function buildFactoryOptionsIncludesAuthWhenPasswordSet(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
            'password' => 'secret123',
        ]);

        $opts = $this->invokeBuildFactoryOptions($backend);

        self::assertArrayHasKey('auth', $opts);
        self::assertSame('secret123', $opts['auth']);
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function buildFactoryOptionsOmitsAuthWhenPasswordEmpty(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
            'password' => '',
        ]);

        $opts = $this->invokeBuildFactoryOptions($backend);

        self::assertArrayNotHasKey('auth', $opts);
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function buildFactoryOptionsOmitsAuthWhenNoPasswordProvided(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
        ]);

        $opts = $this->invokeBuildFactoryOptions($backend);

        self::assertArrayNotHasKey('auth', $opts);
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function buildFactoryOptionsHandlesNullPasswordForV14(): void
    {
        // TYPO3 14 style: array-only constructor, password could be null-ish
        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
        ]);

        $opts = $this->invokeBuildFactoryOptions($backend);

        // No password set means no 'auth' key in base options
        // (rawOptions merge may add 'password' key but auth should not be in base)
        self::assertArrayNotHasKey('auth', $opts);
    }

    // -------------------------------------------------------------------------
    // T010: buildFactoryOptions() — persistent connection ID format
    // -------------------------------------------------------------------------

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function buildFactoryOptionsSetsPersistentIdWithDatabaseSuffix(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
            'database' => 7,
            'persistentConnection' => true,
        ]);

        $opts = $this->invokeBuildFactoryOptions($backend);

        self::assertSame('typo3-cache-7', $opts['persistent']);
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function buildFactoryOptionsPersistentIdUsesDefaultDatabase(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
            'persistentConnection' => true,
        ]);

        $opts = $this->invokeBuildFactoryOptions($backend);

        self::assertSame('typo3-cache-0', $opts['persistent']);
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function buildFactoryOptionsPersistentIsFalseWhenDisabled(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
            'persistentConnection' => false,
        ]);

        $opts = $this->invokeBuildFactoryOptions($backend);

        self::assertFalse($opts['persistent']);
    }

    // -------------------------------------------------------------------------
    // T010: buildFactoryOptions() — extra options (TLS, sentinel, backoff)
    // -------------------------------------------------------------------------

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function buildFactoryOptionsMergesTlsOptions(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
            'tls' => true,
            'ca_file' => '/run/tls/ca.crt',
            'cert_file' => '/run/tls/client.crt',
            'key_file' => '/run/tls/client.key',
            'peer_name' => 'redis.internal',
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ]);

        $opts = $this->invokeBuildFactoryOptions($backend);

        self::assertTrue($opts['tls']);
        self::assertSame('/run/tls/ca.crt', $opts['ca_file']);
        self::assertSame('/run/tls/client.crt', $opts['cert_file']);
        self::assertSame('/run/tls/client.key', $opts['key_file']);
        self::assertSame('redis.internal', $opts['peer_name']);
        self::assertTrue($opts['verify_peer']);
        self::assertTrue($opts['verify_peer_name']);
        self::assertFalse($opts['allow_self_signed']);
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function buildFactoryOptionsMergesSentinelOptions(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
            'sentinel' => true,
            'sentinel_host' => 'sentinel.local',
            'sentinel_port' => 26379,
            'sentinel_service' => 'mymaster',
            'sentinel_password' => 'sentinel-secret',
        ]);

        $opts = $this->invokeBuildFactoryOptions($backend);

        self::assertTrue($opts['sentinel']);
        self::assertSame('sentinel.local', $opts['sentinel_host']);
        self::assertSame(26379, $opts['sentinel_port']);
        self::assertSame('mymaster', $opts['sentinel_service']);
        self::assertSame('sentinel-secret', $opts['sentinel_password']);
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function buildFactoryOptionsMergesBackoffConfig(): void
    {
        $backoff = [
            'algorithm' => 1, // e.g. Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER
            'base' => 500,
            'cap' => 750,
        ];

        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
            'backoff' => $backoff,
        ]);

        $opts = $this->invokeBuildFactoryOptions($backend);

        self::assertSame($backoff, $opts['backoff']);
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function buildFactoryOptionsReadTimeoutFromCamelCase(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
            'readTimeout' => 3.5,
        ]);

        $opts = $this->invokeBuildFactoryOptions($backend);

        // readTimeout is set in base opts, then rawOptions merge overwrites with same value
        self::assertSame(3.5, $opts['readTimeout']);
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function buildFactoryOptionsReadTimeoutFromSnakeCase(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
            'read_timeout' => 2.0,
        ]);

        $opts = $this->invokeBuildFactoryOptions($backend);

        // The base readTimeout should pick up the snake_case fallback
        self::assertSame(2.0, $opts['readTimeout']);
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function buildFactoryOptionsRetryIntervalFromCamelCase(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
            'retryInterval' => 150,
        ]);

        $opts = $this->invokeBuildFactoryOptions($backend);

        self::assertSame(150, $opts['retryInterval']);
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function buildFactoryOptionsRetryIntervalFromSnakeCase(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
            'retry_interval' => 200,
        ]);

        $opts = $this->invokeBuildFactoryOptions($backend);

        // The base retryInterval should pick up the snake_case fallback
        self::assertSame(200, $opts['retryInterval']);
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function buildFactoryOptionsRawOptionsMergeOverridesBaseKeys(): void
    {
        // rawOptions include 'hostname' which is a parent key but also ends up
        // in rawOptions — array_replace should let it through harmlessly.
        $backend = new KeyValueBackend([
            'hostname' => 'redis.example.com',
            'port' => 6380,
            'database' => 2,
        ]);

        $opts = $this->invokeBuildFactoryOptions($backend);

        // The rawOptions merge puts 'hostname' on top of the base opts.
        // This is harmless: factory ignores 'hostname' and uses 'host'.
        self::assertArrayHasKey('hostname', $opts);
        self::assertSame('redis.example.com', $opts['hostname']);
        // The mapped 'host' key should also be present
        self::assertSame('redis.example.com', $opts['host']);
    }

    // -------------------------------------------------------------------------
    // T010: buildFactoryOptions() — TYPO3 14 array-only constructor
    // -------------------------------------------------------------------------

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function buildFactoryOptionsWorksWithV14ArrayConstructor(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => '10.0.0.1',
            'port' => 6381,
            'database' => 4,
            'password' => 'v14pass',
            'tls' => true,
        ]);

        $opts = $this->invokeBuildFactoryOptions($backend);

        self::assertSame('10.0.0.1', $opts['host']);
        self::assertSame(6381, $opts['port']);
        self::assertSame(4, $opts['database']);
        self::assertSame('v14pass', $opts['auth']);
        self::assertTrue($opts['tls']);
    }

    // -------------------------------------------------------------------------
    // T009: initializeObject() — delegates to factory and sets connected
    // -------------------------------------------------------------------------

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function initializeObjectCallsFactoryCreateAndSetsConnected(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ]);

        $redisMock = $this->createMock(\Redis::class);

        $factoryMock = $this->createMock(ConnectionFactoryInterface::class);
        $factoryMock->expects(self::once())
            ->method('create')
            ->willReturn($redisMock);

        // Inject mock factory via reflection
        $factoryProp = new \ReflectionProperty(KeyValueBackend::class, 'factory');
        $factoryProp->setValue($backend, $factoryMock);

        $backend->initializeObject();

        // Verify connected flag is set
        $connectedProp = new \ReflectionProperty($backend, 'connected');
        self::assertTrue($connectedProp->getValue($backend));

        // Verify redis instance is set
        $redisProp = new \ReflectionProperty($backend, 'redis');
        self::assertSame($redisMock, $redisProp->getValue($backend));
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function initializeObjectPassesCorrectOptionsToFactory(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => 'redis.local',
            'port' => 6380,
            'database' => 5,
            'password' => 'mypass',
            'persistentConnection' => true,
            'tls' => true,
            'ca_file' => '/certs/ca.pem',
        ]);

        $capturedOptions = null;
        $redisMock = $this->createMock(\Redis::class);

        $factoryMock = $this->createMock(ConnectionFactoryInterface::class);
        $factoryMock->expects(self::once())
            ->method('create')
            ->with(self::callback(static function (array $opts) use (&$capturedOptions): bool {
                $capturedOptions = $opts;

                return true;
            }))
            ->willReturn($redisMock);

        $factoryProp = new \ReflectionProperty(KeyValueBackend::class, 'factory');
        $factoryProp->setValue($backend, $factoryMock);

        $backend->initializeObject();

        self::assertNotNull($capturedOptions, 'Factory create() must have been called');
        self::assertSame('redis.local', $capturedOptions['host']);
        self::assertSame(6380, $capturedOptions['port']);
        self::assertSame(5, $capturedOptions['database']);
        self::assertSame('mypass', $capturedOptions['auth']);
        self::assertSame('typo3-cache-5', $capturedOptions['persistent']);
        self::assertTrue($capturedOptions['tls']);
        self::assertSame('/certs/ca.pem', $capturedOptions['ca_file']);
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function initializeObjectThrowsCacheExceptionOnRedisException(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
        ]);

        $factoryMock = $this->createMock(ConnectionFactoryInterface::class);
        $factoryMock->expects(self::once())
            ->method('create')
            ->willThrowException(new \RedisException('Connection refused'));

        $factoryProp = new \ReflectionProperty(KeyValueBackend::class, 'factory');
        $factoryProp->setValue($backend, $factoryMock);

        $this->expectException(\TYPO3\CMS\Core\Cache\Exception::class);
        $this->expectExceptionCode(1700100001);
        $this->expectExceptionMessageMatches('/Connection refused/');

        $backend->initializeObject();
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function initializeObjectSetsConnectedFalseOnFailure(): void
    {
        $backend = new KeyValueBackend([
            'hostname' => '127.0.0.1',
        ]);

        $factoryMock = $this->createMock(ConnectionFactoryInterface::class);
        $factoryMock->expects(self::once())
            ->method('create')
            ->willThrowException(new \InvalidArgumentException('Bad config'));

        $factoryProp = new \ReflectionProperty(KeyValueBackend::class, 'factory');
        $factoryProp->setValue($backend, $factoryMock);

        try {
            $backend->initializeObject();
        } catch (\TYPO3\CMS\Core\Cache\Exception) {
            // expected
        }

        $connectedProp = new \ReflectionProperty($backend, 'connected');
        self::assertFalse($connectedProp->getValue($backend));
    }

    // -------------------------------------------------------------------------
    // T010: Reflection-only test (no ext-redis requirement)
    // -------------------------------------------------------------------------

    #[Test]
    public function buildFactoryOptionsMethodExists(): void
    {
        $ref = new \ReflectionMethod(KeyValueBackend::class, 'buildFactoryOptions');

        self::assertTrue($ref->isPrivate(), 'buildFactoryOptions() must be private');
        self::assertSame('array', (string) $ref->getReturnType());
    }

    #[Test]
    public function parentOptionKeysConstantContainsExpectedKeys(): void
    {
        $ref = new \ReflectionClassConstant(KeyValueBackend::class, 'PARENT_OPTION_KEYS');
        $keys = $ref->getValue();

        $expected = [
            'hostname', 'port', 'database', 'username', 'password',
            'compression', 'compressionLevel', 'connectionTimeout',
            'persistentConnection', 'defaultLifetime',
        ];

        self::assertSame($expected, $keys);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function invokeBuildFactoryOptions(KeyValueBackend $backend): array
    {
        $method = new \ReflectionMethod(KeyValueBackend::class, 'buildFactoryOptions');

        return $method->invoke($backend);
    }
}
