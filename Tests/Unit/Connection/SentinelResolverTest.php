<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Connection;

use Moselwal\KeyValueStore\Connection\SentinelResolver;
use Moselwal\KeyValueStore\Connection\TlsContextBuilder;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SentinelResolver::resolveMaster().
 *
 * Validation tests (1-4) exercise the guard clauses that throw before
 * \RedisSentinel is instantiated, so they run without ext-redis.
 *
 * Tests that would reach the \RedisSentinel constructor are marked with
 * #[RequiresPhpExtension('redis')].
 */
final class SentinelResolverTest extends TestCase
{
    private SentinelResolver $subject;

    protected function setUp(): void
    {
        $this->subject = new SentinelResolver();
    }

    // ------------------------------------------------------------------
    // 1. sentinel not enabled
    // ------------------------------------------------------------------

    public function testResolveMasterThrowsWhenSentinelNotEnabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sentinel is not enabled in options.');

        $this->subject->resolveMaster([
            'sentinel' => false,
            'sentinel_host' => '127.0.0.1',
            'sentinel_service' => 'mymaster',
        ]);
    }

    public function testResolveMasterThrowsWhenSentinelKeyIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sentinel is not enabled in options.');

        $this->subject->resolveMaster([
            'sentinel_host' => '127.0.0.1',
            'sentinel_service' => 'mymaster',
        ]);
    }

    // ------------------------------------------------------------------
    // 2. sentinel_host missing
    // ------------------------------------------------------------------

    public function testResolveMasterThrowsWhenHostIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sentinel host and sentinel_service must be set.');

        $this->subject->resolveMaster([
            'sentinel' => true,
            'sentinel_service' => 'mymaster',
        ]);
    }

    // ------------------------------------------------------------------
    // 3. sentinel_service missing
    // ------------------------------------------------------------------

    public function testResolveMasterThrowsWhenServiceIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sentinel host and sentinel_service must be set.');

        $this->subject->resolveMaster([
            'sentinel' => true,
            'sentinel_host' => '127.0.0.1',
        ]);
    }

    // ------------------------------------------------------------------
    // 4. both host and service missing
    // ------------------------------------------------------------------

    public function testResolveMasterThrowsWhenBothHostAndServiceMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sentinel host and sentinel_service must be set.');

        $this->subject->resolveMaster([
            'sentinel' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // 5. the config handed to \RedisSentinel
    // ------------------------------------------------------------------

    /**
     * These reach into buildSentinelConfig() because everything after it in
     * resolveMaster() talks to a live Sentinel.
     *
     * The previous versions subclassed SentinelResolver and restated the
     * config-building logic inside the subclass, which meant they asserted
     * against their own copy and would have kept passing had the real method
     * changed. Once the class became final they stopped running at all — the
     * fatal error was invisible because the job carried allow_failure: true.
     */
    private function buildConfig(SentinelResolver $resolver, array $options): array
    {
        return (new \ReflectionMethod($resolver, 'buildSentinelConfig'))->invoke($resolver, $options);
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function defaultSentinelPortIsUsedWhenNoneIsConfigured(): void
    {
        $config = $this->buildConfig(new SentinelResolver(), [
            'sentinel' => true,
            'sentinel_host' => '10.0.0.1',
            'sentinel_service' => 'mymaster',
        ]);

        self::assertSame(26379, $config['port']);
        self::assertSame('10.0.0.1', $config['host']);
        self::assertSame(1.0, $config['connectTimeout']);
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function hostGetsTheTlsPrefixWhenTlsIsEnabled(): void
    {
        // The real builder, not a double: TlsContextBuilder is final, and
        // letting the two collaborate is the more useful check anyway.
        $config = $this->buildConfig(new SentinelResolver(new TlsContextBuilder()), [
            'sentinel' => true,
            'sentinel_host' => 'sentinel.internal',
            'sentinel_service' => 'mymaster',
            'tls' => true,
        ]);

        self::assertSame('tls://sentinel.internal', $config['host']);
        self::assertSame(
            ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
            $config['ssl'],
            'phpredis expects the ssl options without the outer wrapper, and peer verification stays on by default',
        );
    }

    #[Test]
    #[RequiresPhpExtension('redis')]
    public function optionalEntriesAreOmittedRatherThanSentEmpty(): void
    {
        $config = $this->buildConfig(new SentinelResolver(), [
            'sentinel' => true,
            'sentinel_host' => '10.0.0.1',
            'sentinel_service' => 'mymaster',
            'sentinel_password' => '',
            'persistent_id' => '',
        ]);

        self::assertArrayNotHasKey('auth', $config);
        self::assertArrayNotHasKey('persistent', $config);
        self::assertArrayNotHasKey('ssl', $config);
    }

    /**
     * The counterpart to the test above: it only proved that empty values are
     * dropped, so the two assignments that actually pass a password and a
     * persistent ID to \RedisSentinel were never executed. A wrong key name
     * there would have gone unnoticed until a Sentinel setup failed to
     * authenticate in production.
     */
    #[Test]
    #[RequiresPhpExtension('redis')]
    public function passwordAndPersistentIdAreForwardedWhenSet(): void
    {
        $config = $this->buildConfig(new SentinelResolver(), [
            'sentinel' => true,
            'sentinel_host' => '10.0.0.1',
            'sentinel_service' => 'mymaster',
            'sentinel_password' => 's3cret',
            'persistent_id' => 'sentinel-1',
        ]);

        self::assertSame('s3cret', $config['auth']);
        self::assertSame('sentinel-1', $config['persistent']);
    }
}
