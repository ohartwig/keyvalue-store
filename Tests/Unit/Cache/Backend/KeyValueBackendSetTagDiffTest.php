<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Cache\Backend;

use Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * v4.3.0: KeyValueBackend::set() override fuses the previous Core
 * SETEX + SMEMBERS + MULTI/PIPELINE flow into a single Lua EVAL.
 *
 * The tests pin three behaviours:
 *   1. The flow uses exactly one EVAL — no separate sMembers / multi /
 *      setex calls. Future drift would surface here.
 *   2. Lifetime=0 is normalised to FAKED_UNLIMITED_LIFETIME (one year)
 *      so the Lua script always receives a positive TTL — bit-by-bit
 *      identical to TYPO3 Core's behaviour.
 *   3. Empty tag-set is supported (Lua script handles `for ARGV[5..0]`
 *      correctly — no SADD / SREM calls).
 */
#[RequiresPhpExtension('redis')]
final class KeyValueBackendSetTagDiffTest extends TestCase
{
    #[Test]
    public function setIssuesSingleEvalNotMultiCallFlow(): void
    {
        $redis = $this->createMock(\Redis::class);
        $backend = $this->buildBackend($redis, keyPrefix: 'typo3:c:');

        $redis->expects(self::once())->method('eval')->willReturn(1);
        $redis->expects(self::never())->method('setex');
        $redis->expects(self::never())->method('sMembers');
        $redis->expects(self::never())->method('multi');

        $backend->set('myEntry', 'payload', ['tagA', 'tagB']);
    }

    #[Test]
    public function setPassesNormalisedTtlOfOneYearForLifetimeZero(): void
    {
        $redis = $this->createMock(\Redis::class);
        $backend = $this->buildBackend($redis, keyPrefix: 'typo3:c:');

        $redis->expects(self::once())
            ->method('eval')
            ->with(
                self::isString(),
                self::callback(function (array $args): bool {
                    // KEYS[1], KEYS[2], ARGV[1] = ttl
                    // Args layout: [dataKey, tagsKey, ttl, payload, identifier, tagPrefix, ...tags]
                    return 31536000 === (int) $args[2];
                }),
                2,
            )
            ->willReturn(1);

        $backend->set('myEntry', 'payload', [], 0);
    }

    #[Test]
    public function setPreservesExplicitLifetime(): void
    {
        $redis = $this->createMock(\Redis::class);
        $backend = $this->buildBackend($redis, keyPrefix: 'typo3:c:');

        $redis->expects(self::once())
            ->method('eval')
            ->with(
                self::isString(),
                self::callback(fn(array $args): bool => 3600 === (int) $args[2]),
                2,
            )
            ->willReturn(1);

        $backend->set('myEntry', 'payload', [], 3600);
    }

    #[Test]
    public function setWithEmptyTagsStillIssuesSingleEval(): void
    {
        // The Lua script's `for i = 5, #ARGV do` loop is a no-op when
        // there are zero tags. The call still happens (we always write
        // the data), it just doesn't fan out into SADD/SREM.
        $redis = $this->createMock(\Redis::class);
        $backend = $this->buildBackend($redis, keyPrefix: 'typo3:c:');

        $redis->expects(self::once())
            ->method('eval')
            ->with(
                self::isString(),
                self::callback(function (array $args): bool {
                    // KEYS (2) + ARGV1-4 (ttl, payload, identifier, tagPrefix) = 6 entries.
                    return 6 === count($args);
                }),
                2,
            )
            ->willReturn(1);

        $backend->set('myEntry', 'payload', []);
    }

    #[Test]
    public function setBuildsKeysWithCorePrefixSemantics(): void
    {
        $redis = $this->createMock(\Redis::class);
        $backend = $this->buildBackend($redis, keyPrefix: 'typo3:c:');

        $redis->expects(self::once())
            ->method('eval')
            ->with(
                self::isString(),
                self::callback(function (array $args): bool {
                    // KEYS[1] = keyPrefix + 'identData:' + identifier
                    // KEYS[2] = keyPrefix + 'identTags:' + identifier
                    // ARGV[4] = keyPrefix + 'tagIdents:'   (full prefix Lua appends tag names to)
                    return 'typo3:c:identData:foo' === $args[0]
                        && 'typo3:c:identTags:foo' === $args[1]
                        && 'typo3:c:tagIdents:' === $args[5];
                }),
                2,
            )
            ->willReturn(1);

        $backend->set('foo', 'payload', ['pageId_42']);
    }

    private function buildBackend(\Redis $redis, string $keyPrefix = ''): KeyValueBackend
    {
        $backend = new KeyValueBackend(['hostname' => '127.0.0.1']);

        $reflection = new \ReflectionClass($backend);
        $reflection->getProperty('redis')->setValue($backend, $redis);
        $reflection->getProperty('connected')->setValue($backend, true);
        if ('' !== $keyPrefix) {
            $reflection->getProperty('keyPrefix')->setValue($backend, $keyPrefix);
        }

        return $backend;
    }
}
