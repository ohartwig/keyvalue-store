<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Locking;

use Moselwal\KeyValueStore\Locking\KeyValueLockingStrategy;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireException;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * v4.3.0 Locking audit fixes:
 *
 *   L1 — wait() reuses configured TTL instead of asking the server
 *   L2 — blocking acquire() honours a configurable max-attempts cap
 *   L4 — connection factory called with lazy=true
 *
 * L3 (logger level differentiation) has no observable behaviour beyond
 * the log level itself; covered manually.
 */
#[RequiresPhpExtension('redis')]
final class KeyValueLockingStrategyV43Test extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][KeyValueLockingStrategy::class]['options'] = [
            'hostname' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'ttl' => 10,
        ];
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('x', 32);
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][KeyValueLockingStrategy::class],
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'],
        );
    }

    #[Test]
    public function maxAcquireAttemptsHasConservativeDefault(): void
    {
        $strategy = new KeyValueLockingStrategy('test-subject-default');

        $value = (new \ReflectionProperty($strategy, 'maxAcquireAttempts'))->getValue($strategy);
        self::assertSame(100, $value);
    }

    #[Test]
    public function maxAcquireAttemptsIsConfigurable(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][KeyValueLockingStrategy::class]['options']['maxAcquireAttempts'] = 7;

        $strategy = new KeyValueLockingStrategy('test-subject-configurable');

        $value = (new \ReflectionProperty($strategy, 'maxAcquireAttempts'))->getValue($strategy);
        self::assertSame(7, $value);
    }

    #[Test]
    public function maxAcquireAttemptsClampedToMinimumOne(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][KeyValueLockingStrategy::class]['options']['maxAcquireAttempts'] = 0;

        $strategy = new KeyValueLockingStrategy('test-subject-clamped');

        $value = (new \ReflectionProperty($strategy, 'maxAcquireAttempts'))->getValue($strategy);
        self::assertSame(1, $value);
    }

    #[Test]
    public function acquireBlockingThrowsAfterReachingAttemptsCap(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['locking']['strategies'][KeyValueLockingStrategy::class]['options']['maxAcquireAttempts'] = 3;

        $strategy = new KeyValueLockingStrategy('test-subject-cap');

        // Inject mock-Redis that always reports "lock held" and signals
        // the wait queue on each blPop so the loop progresses but never
        // succeeds.
        $redis = $this->createMock(\Redis::class);
        $redis->method('set')->willReturn(false); // tryLock always fails
        $redis->method('blPop')->willReturn(['mutex', 'signal']);

        (new \ReflectionProperty($strategy, 'redis'))->setValue($strategy, $redis);

        $this->expectException(LockAcquireException::class);
        $this->expectExceptionCode(1700000013);

        $strategy->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);
    }

    #[Test]
    public function waitUsesConfiguredTtlInsteadOfQueryingServer(): void
    {
        $strategy = new KeyValueLockingStrategy('test-subject-l1');

        $redis = $this->createMock(\Redis::class);
        // The whole point of L1: wait() must NOT issue a TTL roundtrip.
        $redis->expects(self::never())->method('ttl');
        $redis->expects(self::once())
            ->method('blPop')
            ->with(self::anything(), 10) // ttl=10 from setUp
            ->willReturn(['mutex', 'released']);

        (new \ReflectionProperty($strategy, 'redis'))->setValue($strategy, $redis);

        $method = new \ReflectionMethod($strategy, 'wait');
        $result = $method->invoke($strategy);

        self::assertSame('released', $result);
    }

    #[Test]
    public function mapOptionsForceslazyConnect(): void
    {
        $strategy = new KeyValueLockingStrategy('test-subject-l4');

        $method = new \ReflectionMethod($strategy, 'mapOptions');
        $opts = $method->invoke($strategy, ['hostname' => '127.0.0.1']);

        self::assertArrayHasKey('lazy', $opts);
        self::assertTrue($opts['lazy'], 'Locking factory connection must default to lazy=true (no eager ping)');
    }
}
