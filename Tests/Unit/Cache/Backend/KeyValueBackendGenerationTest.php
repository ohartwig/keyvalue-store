<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Cache\Backend;

use Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Exception;

/**
 * The client side of {@see \Moselwal\KeyValueStore\Cache\Backend\GenerationBackendInterface}:
 * what goes over the wire, and that a failure is an exception rather than a
 * silent skip. The server side is covered by the functional test of the
 * same name.
 */
#[RequiresPhpExtension('redis')]
final class KeyValueBackendGenerationTest extends TestCase
{
    #[Test]
    public function aSetWithGenerationIsExactlyOneEvalWithEntryGenerationAndGuardKeys(): void
    {
        $redis = $this->createMock(\Redis::class);
        $backend = $this->buildBackend($redis, keyPrefix: 'typo3:c:');

        $redis->expects(self::once())
            ->method('eval')
            ->with(
                self::isString(),
                [
                    'typo3:c:identData:entry',
                    'typo3:c:identTags:entry',
                    'typo3:c:generation:memo',
                    'typo3:c:identData:legacy',
                    60,
                    'payload',
                    'entry',
                    'typo3:c:tagIdents:',
                    'g1',
                    'dirty-',
                    'tagA',
                    'tagB',
                ],
                4,
            )
            ->willReturn([0, 1, 'g1']);
        $redis->expects(self::never())->method('get');
        $redis->expects(self::never())->method('set');
        $redis->expects(self::never())->method('setex');
        $redis->expects(self::never())->method('exists');
        $redis->expects(self::never())->method('multi');

        $result = $backend->setWithGeneration('memo', 'g1', static fn() => $backend->set('entry', 'payload', ['tagA', 'tagB'], 60), 'legacy');

        self::assertTrue($result->written);
        self::assertSame('g1', $result->generation);
        self::assertFalse($result->guardEntryExists);
    }

    #[Test]
    public function withoutAGuardTheScriptGetsThreeKeys(): void
    {
        $redis = $this->createMock(\Redis::class);
        $backend = $this->buildBackend($redis);

        $redis->expects(self::once())
            ->method('eval')
            ->with(self::isString(), self::callback(static fn(array $args): bool => 'generation:memo' === $args[2] && 31536000 === $args[3]), 3)
            ->willReturn([0, 0, 'dirty-other']);

        $result = $backend->setWithGeneration('memo', 'g1', static fn() => $backend->set('entry', 'payload', [], 0));

        self::assertFalse($result->written);
        self::assertTrue($result->isDirty());
    }

    #[Test]
    public function aScriptTheServerRefusesIsAnException(): void
    {
        $redis = $this->createStub(\Redis::class);
        $backend = $this->buildBackend($redis);
        $redis->method('eval')->willReturn(false);
        $redis->method('getLastError')->willReturn('NOPERM this user has no permissions to run the eval command');

        $thrown = 0;
        foreach ([
            static fn() => $backend->readGeneration('memo'),
            static fn() => $backend->writeGeneration('memo', 'dirty-mine'),
            static fn() => $backend->publishGeneration('memo', 'g1', 'dirty-mine'),
            static fn() => $backend->setWithGeneration('memo', 'g1', static fn() => $backend->set('entry', 'payload')),
        ] as $call) {
            try {
                $call();
            } catch (Exception $e) {
                self::assertStringContainsString('NOPERM', $e->getMessage());
                ++$thrown;
            }
        }

        self::assertSame(4, $thrown);
    }

    #[Test]
    public function anUnconnectedBackendThrowsInsteadOfSkipping(): void
    {
        $redis = $this->createMock(\Redis::class);
        $backend = $this->buildBackend($redis, connected: false);
        $redis->expects(self::never())->method('eval');

        $thrown = 0;
        foreach ([
            static fn() => $backend->readGeneration('memo'),
            static fn() => $backend->writeGeneration('memo', 'dirty-mine'),
            static fn() => $backend->publishGeneration('memo', 'g1', 'dirty-mine'),
            static fn() => $backend->setWithGeneration('memo', 'g1', static fn() => $backend->set('entry', 'payload')),
        ] as $call) {
            try {
                $call();
            } catch (Exception) {
                ++$thrown;
            }
        }

        self::assertSame(4, $thrown);
    }

    #[Test]
    public function nestingIsRejectedAndLeavesTheOuterCallIntact(): void
    {
        $redis = $this->createMock(\Redis::class);
        $backend = $this->buildBackend($redis);
        $redis->expects(self::once())->method('eval')->willReturn([0, 1, 'outer']);

        $nested = null;
        $result = $backend->setWithGeneration('memo', 'outer', static function () use ($backend, &$nested): void {
            try {
                $backend->setWithGeneration('memo', 'inner', static fn() => $backend->set('other', 'x'));
            } catch (\LogicException $e) {
                $nested = $e;
            }
            $backend->set('entry', 'payload');
        });

        self::assertInstanceOf(\LogicException::class, $nested);
        self::assertSame('outer', $result->generation);
    }

    private function buildBackend(\Redis $redis, string $keyPrefix = '', bool $connected = true): KeyValueBackend
    {
        $backend = new KeyValueBackend(['hostname' => '127.0.0.1']);

        $reflection = new \ReflectionClass($backend);
        $reflection->getProperty('redis')->setValue($backend, $redis);
        $reflection->getProperty('connected')->setValue($backend, $connected);
        if ('' !== $keyPrefix) {
            $reflection->getProperty('keyPrefix')->setValue($backend, $keyPrefix);
        }

        return $backend;
    }
}
