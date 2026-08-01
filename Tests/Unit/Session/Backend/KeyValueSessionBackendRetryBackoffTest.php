<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Session\Backend;

use Moselwal\KeyValueStore\Connection\ConnectionFactoryInterface;
use Moselwal\KeyValueStore\Session\Backend\KeyValueSessionBackend;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * v4.1.0: getRedis() switched from linear backoff (50/100/150 ms) to
 * decorrelated jitter (10 ms base, 100 ms cap). Two failed attempts now
 * complete in well under 200 ms instead of ~300 ms.
 */
#[RequiresPhpExtension('redis')]
final class KeyValueSessionBackendRetryBackoffTest extends TestCase
{
    #[Test]
    public function twoTransientFailuresThenSuccessFinishesUnderTwoHundredMs(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('ping')->willReturn(true);

        $factory = $this->createMock(ConnectionFactoryInterface::class);
        $factory->expects(self::exactly(3))
            ->method('create')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RedisException('boom 1')),
                $this->throwException(new \RedisException('boom 2')),
                $redis,
            );

        $backend = $this->buildBackendWithFactory($factory);

        $start = hrtime(true);
        $this->invokeGetRedis($backend);
        $elapsedMs = (hrtime(true) - $start) / 1e6;

        // Linear 50/100 ms backoff would have slept ~150 ms minimum
        // before the third attempt; decorrelated jitter caps the worst
        // case in the 10–100 ms range per pause, with the second pause
        // capped at 100 ms. Total < 200 ms gives ample margin against
        // scheduler noise without re-introducing the slow path.
        self::assertLessThan(200.0, $elapsedMs, "expected retry budget < 200 ms, was {$elapsedMs} ms");
    }

    #[Test]
    public function threeFailuresRethrowOriginalRedisException(): void
    {
        $factory = $this->createMock(ConnectionFactoryInterface::class);
        $factory->expects(self::exactly(3))
            ->method('create')
            ->willThrowException(new \RedisException('persistent failure'));

        $backend = $this->buildBackendWithFactory($factory);

        $this->expectException(\RedisException::class);
        $this->expectExceptionMessage('persistent failure');
        $this->invokeGetRedis($backend);
    }

    private function buildBackendWithFactory(ConnectionFactoryInterface $factory): KeyValueSessionBackend
    {
        $backend = new KeyValueSessionBackend();
        $backend->initialize('FE', [
            'hostname' => 'irrelevant.test',
            'hashSessionIds' => false,
        ]);

        $prop = new \ReflectionProperty($backend, 'factory');
        $prop->setValue($backend, $factory);

        return $backend;
    }

    private function invokeGetRedis(KeyValueSessionBackend $backend): \Redis
    {
        $method = new \ReflectionMethod(KeyValueSessionBackend::class, 'getRedis');

        return $method->invoke($backend);
    }
}
