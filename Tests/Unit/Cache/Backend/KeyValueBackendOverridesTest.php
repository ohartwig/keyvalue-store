<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Cache\Backend;

use Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * v4.2.0: KeyValueBackend overrides three TYPO3 Core RedisBackend
 * methods to drop two well-known anti-patterns:
 *
 *   1. `KEYS prefix*` (server-side blocking) → `SCAN` loop
 *   2. `DEL` (blocking memory reclaim) → `UNLINK` (async free)
 *
 * The flushByTags() override additionally collapses the N×flushByTag()
 * fan-out into a single pipeline.
 *
 * These tests pin the override semantics via reflection + a mocked
 * phpredis client. They run only when ext-redis is installed so the
 * \Redis class is available for createMock() — in CI / the dev
 * container that is always the case.
 */
#[RequiresPhpExtension('redis')]
final class KeyValueBackendOverridesTest extends TestCase
{
    #[Test]
    public function flushNeverCallsKeysAndAlwaysUsesUnlink(): void
    {
        $redis = $this->createMock(\Redis::class);
        // Force the prefixed flush branch — flush() with empty keyPrefix
        // takes the FLUSHDB shortcut and is uninteresting here.
        $backend = $this->buildBackendWithMockRedis($redis, keyPrefix: 'typo3:c:');

        $redis->method('scan')
            ->willReturnCallback(function (&$cursor, string $pattern, int $count) {
                self::assertSame('typo3:c:*', $pattern);
                $cursor = 0;

                return ['typo3:c:identData:foo', 'typo3:c:identData:bar'];
            });

        $redis->expects(self::never())->method('keys');
        $redis->expects(self::never())->method('del');
        $redis->expects(self::once())
            ->method('unlink')
            ->with('typo3:c:identData:foo', 'typo3:c:identData:bar');

        $backend->flush();
    }

    #[Test]
    public function flushByTagsBundlesAllTagsIntoOnePipeline(): void
    {
        $redis = $this->createMock(\Redis::class);
        $backend = $this->buildBackendWithMockRedis($redis, keyPrefix: 'typo3:c:');

        // What this guards is that N tags cost one round of work, not N
        // flushByTag() invocations each doing their own union — the shape
        // before v4.2.0.
        //
        // That is two sUnion calls, not one, and both are needed: the first
        // asks which identifiers carry these tags, the second asks which tags
        // those identifiers carry, so the reverse index can be cleaned up
        // without leaving orphans behind. Pinning it at one call described a
        // version of flushByTags that would not work.
        $unions = [];
        $redis->expects(self::exactly(2))
            ->method('sUnion')
            ->willReturnCallback(static function (string ...$keys) use (&$unions): array {
                $unions[] = $keys;

                return 1 === count($unions) ? ['e1', 'e2'] : ['foo', 'bar', 'baz'];
            });

        $pipeline = $this->createMock(\Redis::class);
        $pipeline->method('sAdd')->willReturnSelf();
        $pipeline->method('sDiffStore')->willReturnSelf();
        // The cleanup MUST be UNLINK; DEL is the regression we are
        // guarding against.
        $pipeline->expects(self::once())->method('unlink')->willReturnSelf();
        $pipeline->expects(self::never())->method('del');
        $pipeline->method('exec')->willReturn([]);

        $redis->expects(self::once())
            ->method('multi')
            ->with(\Redis::PIPELINE)
            ->willReturn($pipeline);

        $backend->flushByTags(['foo', 'bar', 'baz']);

        self::assertSame(
            ['typo3:c:tagIdents:foo', 'typo3:c:tagIdents:bar', 'typo3:c:tagIdents:baz'],
            $unions[0],
            'all three tags must be resolved together, not one union per tag',
        );
        self::assertSame(
            ['typo3:c:identTags:e1', 'typo3:c:identTags:e2'],
            $unions[1],
            'the reverse index is collected for every identifier found, in one go',
        );
    }

    #[Test]
    public function flushByTagsShortCircuitsWhenTagSetsAreEmpty(): void
    {
        $redis = $this->createMock(\Redis::class);
        $backend = $this->buildBackendWithMockRedis($redis, keyPrefix: 'typo3:c:');

        // No entries reference the tags — we only need to drop the
        // tag-set keys themselves, no identifier-cleanup pipeline.
        $redis->method('sUnion')->willReturn([]);
        $redis->expects(self::once())
            ->method('unlink')
            ->with('typo3:c:tagIdents:foo', 'typo3:c:tagIdents:bar');
        $redis->expects(self::never())->method('multi');

        $backend->flushByTags(['foo', 'bar']);
    }

    #[Test]
    public function flushByTagDelegatesToFlushByTagsForSingleTag(): void
    {
        // Single-tag and multi-tag must share one optimisation path —
        // a separate flushByTag() code path would be drift waiting to
        // happen.
        $redis = $this->createMock(\Redis::class);
        $backend = $this->buildBackendWithMockRedis($redis, keyPrefix: 'typo3:c:');

        $redis->expects(self::once())
            ->method('sUnion')
            ->with('typo3:c:tagIdents:singleTag')
            ->willReturn([]);
        $redis->expects(self::once())
            ->method('unlink')
            ->with('typo3:c:tagIdents:singleTag');

        $backend->flushByTag('singleTag');
    }

    #[Test]
    public function collectGarbageUsesScanNotKeys(): void
    {
        $redis = $this->createMock(\Redis::class);
        $backend = $this->buildBackendWithMockRedis($redis, keyPrefix: 'typo3:c:');

        $redis->method('scan')
            ->willReturnCallback(function (&$cursor, string $pattern, int $count) {
                self::assertSame('typo3:c:identTags:*', $pattern);
                $cursor = 0;

                return ['typo3:c:identTags:orphan1'];
            });

        // The data key is missing → orphan, gets cleaned up.
        $redis->method('exists')->willReturn(0);
        $redis->method('sMembers')->willReturn(['some-tag']);

        $pipeline = $this->createMock(\Redis::class);
        $pipeline->expects(self::once())->method('unlink')->with('typo3:c:identTags:orphan1')->willReturnSelf();
        $pipeline->expects(self::once())->method('srem')->willReturnSelf();
        $pipeline->method('exec')->willReturn([]);

        $redis->method('multi')->willReturn($pipeline);
        $redis->expects(self::never())->method('keys');

        $backend->collectGarbage();
    }

    /**
     * Build a KeyValueBackend with an injected phpredis mock. We bypass
     * the connection factory entirely so no TCP/TLS is needed.
     */
    private function buildBackendWithMockRedis(\Redis $redis, string $keyPrefix = ''): KeyValueBackend
    {
        $backend = new KeyValueBackend(['hostname' => '127.0.0.1']);

        $reflection = new \ReflectionClass($backend);
        $redisProp = $reflection->getProperty('redis');
        $redisProp->setValue($backend, $redis);
        $connectedProp = $reflection->getProperty('connected');
        $connectedProp->setValue($backend, true);
        if ('' !== $keyPrefix) {
            $keyPrefixProp = $reflection->getProperty('keyPrefix');
            $keyPrefixProp->setValue($backend, $keyPrefix);
        }

        return $backend;
    }
}
