<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Session\Backend;

use Moselwal\KeyValueStore\Session\Backend\KeyValueSessionBackend;
use Moselwal\KeyValueStore\Tests\Unit\Session\Backend\Fixtures\InMemoryRedis;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Session\Backend\Exception\SessionNotUpdatedException;

/**
 * update() reads the record once and writes it back with a compare-and-set
 * script. WATCH/MULTI/EXEC took six roundtrips for the same guarantee and
 * could leave a WATCH on a connection kept for the next request.
 */
#[RequiresPhpExtension('redis')]
final class KeyValueSessionBackendUpdateCompareAndSetTest extends TestCase
{
    private const string KEY = 'typo3:sess:fe:sid';

    #[Test]
    public function updateIsOneGetAndOneCompareAndSet(): void
    {
        $stored = '{"ses_id":"sid","ses_data":"old","extra":"keep"}';

        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('get')->with(self::KEY)->willReturn($stored);
        $redis->expects(self::once())
            ->method('eval')
            ->with(
                $this->compareAndSetScript(),
                self::callback(static function (array $args) use ($stored): bool {
                    $written = json_decode((string) ($args[2] ?? ''), true);

                    return self::KEY === $args[0]
                        && $stored === $args[1]
                        && is_array($written)
                        && 'new' === $written['ses_data']
                        && 'keep' === $written['extra']
                        && '3600' === $args[3]
                        && 4 === count($args);
                }),
                1,
            )
            ->willReturn(1);
        foreach (['watch', 'unwatch', 'multi', 'exec', 'ttl', 'setex'] as $method) {
            $redis->expects(self::never())->method($method);
        }

        $record = $this->backend($redis)->update('sid', ['ses_data' => 'new']);

        self::assertSame('new', $record['ses_data']);
        self::assertSame('keep', $record['extra']);
    }

    #[Test]
    public function updateRedoesTheMergeOnTheNewerRecordWhenTheCompareFails(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::exactly(2))->method('get')->willReturnOnConsecutiveCalls(
            '{"ses_data":"old","other":"first"}',
            '{"ses_data":"old","other":"second"}',
        );
        $redis->expects(self::exactly(2))->method('eval')->willReturnOnConsecutiveCalls(0, 1);

        $record = $this->backend($redis)->update('sid', ['ses_data' => 'new']);

        self::assertSame('second', $record['other'], 'the concurrent write must survive');
        self::assertSame('new', $record['ses_data']);
    }

    #[Test]
    public function updateGivesUpAfterTwoLostRaces(): void
    {
        $redis = new InMemoryRedis();
        $redis->data[self::KEY] = '{"ses_data":"old"}';
        $redis->loseCompareAndSet = true;

        try {
            $this->backend($redis)->update('sid', ['ses_data' => 'new']);
            self::fail('expected SessionNotUpdatedException');
        } catch (SessionNotUpdatedException $e) {
            self::assertSame(1730001031, $e->getCode());
        }

        self::assertCount(2, $redis->evalCalls);
        self::assertSame('{"ses_data":"old"}', $redis->data[self::KEY]);
    }

    #[Test]
    public function updateOfAMissingSessionWritesNothing(): void
    {
        $redis = new InMemoryRedis();

        try {
            $this->backend($redis)->update('sid', ['ses_data' => 'new']);
            self::fail('expected SessionNotUpdatedException');
        } catch (SessionNotUpdatedException $e) {
            self::assertSame(1730001030, $e->getCode());
        }

        self::assertSame([], $redis->evalCalls);
        self::assertSame([], $redis->data);
    }

    private function backend(\Redis $redis): KeyValueSessionBackend
    {
        $backend = new KeyValueSessionBackend();
        $backend->initialize('FE', [
            'hostname' => 'irrelevant.test',
            'sessionLifetime' => 3600,
            // Asserting the raw key keeps the test about the write path.
            'hashSessionIds' => false,
        ]);

        new \ReflectionProperty($backend, 'redis')->setValue($backend, $redis);

        return $backend;
    }

    private function compareAndSetScript(): string
    {
        return (string) new \ReflectionClassConstant(KeyValueSessionBackend::class, 'COMPARE_AND_SET_SCRIPT')->getValue();
    }
}
