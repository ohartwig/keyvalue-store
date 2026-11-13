<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Functional\Cache\Backend;

use Moselwal\KeyValueStore\Cache\Backend\GenerationBackendInterface;
use Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * Generations ({@see GenerationBackendInterface}) against a real server:
 * the raw key format, the one-round-trip set, and the rule that a foreign
 * in-flight marker stays. Requires a running Redis/Valkey.
 */
#[RequiresPhpExtension('redis')]
final class KeyValueBackendGenerationTest extends TestCase
{
    private const string NAME = 'memo_v2';

    private const string LEGACY = '__legacy_generation__';

    private KeyValueBackend $backend;

    private VariableFrontend $frontend;

    private \Redis $redis;

    private string $host;

    private int $port;

    protected function setUp(): void
    {
        $this->host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $this->port = (int) (getenv('REDIS_PORT') ?: 6379);
        try {
            $this->redis = new \Redis();
            $this->redis->connect($this->host, $this->port, 1.0);
            $this->redis->select(15);
        } catch (\RedisException) {
            self::markTestSkipped('Redis is not available at ' . $this->host . ':' . $this->port);
        }
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('k', 96);
        [$this->backend, $this->frontend] = $this->backendAndFrontend();
        $this->backend->flush();
    }

    protected function tearDown(): void
    {
        try {
            $this->redis->flushDB();
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{KeyValueBackend, VariableFrontend}
     */
    private function backendAndFrontend(array $options = [], string $keyPrefix = ''): array
    {
        $backend = new KeyValueBackend(['hostname' => $this->host, 'port' => $this->port, 'database' => 15] + $options);
        if ('' !== $keyPrefix) {
            (new \ReflectionClass($backend))->getProperty('keyPrefix')->setValue($backend, $keyPrefix);
        }
        $frontend = new VariableFrontend('test_cache', $backend);
        $backend->setCache($frontend);
        $backend->initializeObject();

        return [$backend, $frontend];
    }

    /** @return array<string, int> calls per command since the last reset */
    private function commandCalls(): array
    {
        $calls = [];
        foreach ($this->redis->info('commandstats') as $command => $stats) {
            if (1 === preg_match('/calls=(\d+)/', (string) $stats, $m)) {
                $calls[substr((string) $command, \strlen('cmdstat_'))] = (int) $m[1];
            }
        }
        unset($calls['info'], $calls['config|resetstat']);

        return $calls;
    }

    public function testASetWithGenerationIsOneRoundTripAndStoresEntryTagsAndGeneration(): void
    {
        // Connect (lazy) and select before counting.
        $this->backend->has('warm-up');
        $this->redis->rawCommand('CONFIG', 'RESETSTAT');

        $result = $this->backend->setWithGeneration(
            self::NAME,
            'g1',
            fn() => $this->frontend->set('entry', ['some' => 'value'], ['tag_a', 'tag_b'], 60),
            self::LEGACY,
        );

        $calls = $this->commandCalls();
        self::assertSame(1, $calls['eval'] ?? 0, 'one script');
        // Everything else the server counted ran inside that script.
        self::assertSame(
            ['eval', 'exists', 'get', 'sadd', 'set', 'setex', 'smembers'],
            (function () use ($calls): array {
                $names = array_keys($calls);
                sort($names);

                return $names;
            })(),
        );
        self::assertSame(1, $calls['get'], 'the script read the generation once; no client-side read');
        self::assertSame(1, $calls['exists'], 'the guard check ran inside the script');

        self::assertTrue($result->written);
        self::assertFalse($result->guardEntryExists);
        self::assertSame('g1', $result->generation);
        self::assertSame(['some' => 'value'], $this->frontend->get('entry'), 'the frontend reads what it encoded');
        self::assertSame('g1', $this->redis->get('generation:' . self::NAME), 'raw value');
        self::assertSame(-1, $this->redis->ttl('generation:' . self::NAME), 'no expiry');
        self::assertEqualsCanonicalizing(['tag_a', 'tag_b'], $this->redis->sMembers('identTags:entry'));
        self::assertSame(['entry'], $this->backend->findIdentifiersByTag('tag_a'));
        $ttl = $this->redis->ttl('identData:entry');
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(60, $ttl);
    }

    public function testTheGenerationChangesWithEverySet(): void
    {
        $seen = [];
        foreach (['g1', 'g2', 'g3'] as $generation) {
            $this->backend->setWithGeneration(self::NAME, $generation, fn() => $this->frontend->set('entry', $generation));
            $seen[] = $this->backend->readGeneration(self::NAME)->generation;
        }

        self::assertSame(['g1', 'g2', 'g3'], $seen);
    }

    public function testAForeignInFlightMarkerStaysWhileTheEntryIsStored(): void
    {
        $this->backend->writeGeneration(self::NAME, 'dirty-other');

        $result = $this->backend->setWithGeneration(self::NAME, 'g1', fn() => $this->frontend->set('entry', 'new'));

        self::assertFalse($result->written);
        self::assertTrue($result->isDirty());
        self::assertSame('dirty-other', $result->generation);
        self::assertSame('dirty-other', $this->redis->get('generation:' . self::NAME));
        self::assertSame('new', $this->frontend->get('entry'), 'the entry is stored all the same');
    }

    public function testNothingIsStoredWhileTheGuardEntryExists(): void
    {
        $this->backend->writeGeneration(self::NAME, 'g0');
        $this->frontend->set(self::LEGACY, 'whatever a legacy writer keeps here');

        $result = $this->backend->setWithGeneration(self::NAME, 'g1', fn() => $this->frontend->set('entry', 'new', ['tag_a']), self::LEGACY);

        self::assertTrue($result->guardEntryExists);
        self::assertFalse($result->written);
        self::assertSame('g0', $result->generation);
        self::assertFalse($this->backend->has('entry'));
        self::assertSame([], $this->redis->sMembers('tagIdents:tag_a'));
        self::assertSame('g0', $this->redis->get('generation:' . self::NAME));

        // Without naming the guard the same call goes through.
        self::assertTrue($this->backend->setWithGeneration(self::NAME, 'g1', fn() => $this->frontend->set('entry', 'new'))->written);
    }

    public function testASetWithGenerationReplacesTheTagsLikeAPlainSet(): void
    {
        $this->backend->setWithGeneration(self::NAME, 'g1', fn() => $this->backend->set('entry', 'A', ['keep', 'drop']));
        $this->backend->setWithGeneration(self::NAME, 'g2', fn() => $this->backend->set('entry', 'B', ['keep', 'add']));

        self::assertSame('B', $this->backend->get('entry'));
        self::assertEqualsCanonicalizing(['keep', 'add'], $this->redis->sMembers('identTags:entry'));
        self::assertSame([], $this->redis->sMembers('tagIdents:drop'));
        self::assertSame(['entry'], $this->redis->sMembers('tagIdents:add'));
        self::assertSame(['entry'], $this->redis->sMembers('tagIdents:keep'));
    }

    public function testUnlimitedLifetimeIsOneYearAsForAPlainSet(): void
    {
        $this->backend->setWithGeneration(self::NAME, 'g1', fn() => $this->backend->set('entry', 'A', [], 0));

        self::assertGreaterThan(31536000 - 5, $this->redis->ttl('identData:entry'));
    }

    public function testPublishReplacesTheOwnMarkerACleanValueAndNothingButNotAForeignMarker(): void
    {
        self::assertTrue($this->backend->publishGeneration(self::NAME, 'g1', 'dirty-mine')->written, 'nothing stored');

        $this->backend->writeGeneration(self::NAME, 'dirty-mine');
        self::assertTrue($this->backend->publishGeneration(self::NAME, 'g2', 'dirty-mine')->written, 'own marker');

        self::assertTrue($this->backend->publishGeneration(self::NAME, 'g3', 'dirty-mine')->written, 'a clean value');
        self::assertSame('g3', $this->backend->readGeneration(self::NAME)->generation);

        $this->backend->writeGeneration(self::NAME, 'dirty-other');
        $deferred = $this->backend->publishGeneration(self::NAME, 'g4', 'dirty-mine');
        self::assertFalse($deferred->written);
        self::assertSame('dirty-other', $deferred->generation);
        self::assertSame('dirty-other', $this->redis->get('generation:' . self::NAME));
    }

    public function testReadAndWriteReportTheGuardEntry(): void
    {
        $absent = $this->backend->readGeneration(self::NAME, self::LEGACY);
        self::assertNull($absent->generation);
        self::assertFalse($absent->guardEntryExists);
        self::assertFalse($absent->written);

        $this->frontend->set(self::LEGACY, 'x');

        self::assertTrue($this->backend->readGeneration(self::NAME, self::LEGACY)->guardEntryExists);
        $written = $this->backend->writeGeneration(self::NAME, 'dirty-mine', self::LEGACY);
        self::assertTrue($written->guardEntryExists);
        self::assertTrue($written->written, 'a marker is written whatever the guard says');
        self::assertTrue($this->backend->publishGeneration(self::NAME, 'g1', 'dirty-mine', self::LEGACY)->guardEntryExists);
        self::assertFalse($this->backend->readGeneration(self::NAME)->guardEntryExists, 'no guard named');
    }

    public function testReadAndWriteAreOneRoundTripEach(): void
    {
        $this->backend->has('warm-up');
        $this->redis->rawCommand('CONFIG', 'RESETSTAT');

        $this->backend->writeGeneration(self::NAME, 'dirty-mine', self::LEGACY);
        $this->backend->publishGeneration(self::NAME, 'g1', 'dirty-mine', self::LEGACY);
        $this->backend->readGeneration(self::NAME, self::LEGACY);

        self::assertSame(3, $this->commandCalls()['eval']);
    }

    public function testAGenerationIsNotAnEntry(): void
    {
        $this->backend->writeGeneration(self::NAME, 'g1');

        self::assertFalse($this->backend->has(self::NAME));
        self::assertFalse($this->backend->get(self::NAME));
        self::assertFalse($this->backend->remove(self::NAME));
        self::assertSame('g1', $this->backend->readGeneration(self::NAME)->generation);
    }

    public function testTagFlushesAndGarbageCollectionLeaveTheGenerationAndFlushRemovesIt(): void
    {
        $this->backend->setWithGeneration(self::NAME, 'g1', fn() => $this->backend->set('entry', 'A', ['tag_a']));

        $this->backend->flushByTag('tag_a');
        $this->backend->flushByTags(['tag_a', 'tag_b']);
        $this->backend->collectGarbage();
        self::assertSame('g1', $this->backend->readGeneration(self::NAME)->generation);

        $this->backend->flush();
        self::assertNull($this->backend->readGeneration(self::NAME)->generation);
    }

    public function testWithAKeyPrefixTheGenerationLivesUnderItAndAFlushOfThePrefixRemovesIt(): void
    {
        [$backend, $frontend] = $this->backendAndFrontend(keyPrefix: 'site:');
        $this->backend->writeGeneration(self::NAME, 'unprefixed');
        $frontend->set(self::LEGACY, 'x');

        self::assertTrue($backend->writeGeneration(self::NAME, 'g1', self::LEGACY)->guardEntryExists, 'the guard is looked up under the prefix');
        self::assertSame('g1', $this->redis->get('site:generation:' . self::NAME));
        self::assertSame('unprefixed', $this->backend->readGeneration(self::NAME)->generation);

        $backend->flush();

        self::assertNull($backend->readGeneration(self::NAME)->generation);
        self::assertSame('unprefixed', $this->backend->readGeneration(self::NAME)->generation, 'the other key space is untouched');
    }

    public function testTheGenerationStaysRawWithCompressionAndAClientSerializer(): void
    {
        [$backend, $frontend] = $this->backendAndFrontend(['compression' => true, 'serializer' => 'php']);

        $backend->writeGeneration(self::NAME, 'dirty-mine');
        self::assertSame('dirty-mine', $backend->readGeneration(self::NAME)->generation);

        $result = $backend->setWithGeneration(self::NAME, 'g1', fn() => $backend->set('entry', str_repeat('payload ', 50), ['tag_a']));
        self::assertFalse($result->written, 'the marker written above is foreign to a set');

        $backend->publishGeneration(self::NAME, 'g2', 'dirty-mine');
        $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
        self::assertSame('g2', $this->redis->get('generation:' . self::NAME));
        self::assertSame('g2', $backend->readGeneration(self::NAME)->generation);
        self::assertSame(str_repeat('payload ', 50), $backend->get('entry'));
        unset($frontend);
    }

    public function testAClosureWithoutASetIsAnErrorAndLeavesNothingArmed(): void
    {
        try {
            $this->backend->setWithGeneration(self::NAME, 'g1', static function (): void {});
            self::fail('expected a LogicException');
        } catch (\LogicException $e) {
            self::assertSame(1700100013, $e->getCode());
        }

        $this->backend->set('entry', 'plain');
        self::assertNull($this->backend->readGeneration(self::NAME)->generation, 'the later set carried no generation');
    }

    public function testAnExceptionInTheClosureLeavesNothingArmed(): void
    {
        try {
            $this->backend->setWithGeneration(self::NAME, 'g1', fn() => $this->frontend->set('entry', 'x', ['not a valid tag']));
            self::fail('expected the frontend to reject the tag');
        } catch (\InvalidArgumentException) {
        }

        $this->backend->set('entry', 'plain');
        self::assertNull($this->backend->readGeneration(self::NAME)->generation);
        self::assertSame('plain', $this->backend->get('entry'));
    }

    public function testOnlyTheFirstSetOfTheClosureCarriesTheGeneration(): void
    {
        $this->backend->has('warm-up');
        $this->redis->rawCommand('CONFIG', 'RESETSTAT');

        $this->backend->setWithGeneration(self::NAME, 'g1', function (): void {
            $this->backend->set('first', 'A');
            $this->backend->set('second', 'B');
        });

        self::assertSame(1, $this->commandCalls()['set'], 'one generation write');
        self::assertSame('g1', $this->backend->readGeneration(self::NAME)->generation);
        self::assertSame('B', $this->backend->get('second'));
    }

    public function testInvalidNamesAndGenerationsAreRejected(): void
    {
        $rejected = 0;
        foreach ([
            fn() => $this->backend->readGeneration('not valid'),
            fn() => $this->backend->readGeneration(''),
            fn() => $this->backend->writeGeneration(self::NAME, ''),
            fn() => $this->backend->publishGeneration(self::NAME, 'dirty-x', 'dirty-mine'),
            fn() => $this->backend->publishGeneration(self::NAME, 'g1', ''),
            fn() => $this->backend->setWithGeneration(self::NAME, 'dirty-x', fn() => $this->backend->set('entry', 'A')),
            fn() => $this->backend->setWithGeneration(self::NAME, '', fn() => $this->backend->set('entry', 'A')),
        ] as $call) {
            try {
                $call();
            } catch (\InvalidArgumentException) {
                ++$rejected;
            }
        }

        self::assertSame(7, $rejected);
        self::assertFalse($this->backend->has('entry'));
        self::assertInstanceOf(GenerationBackendInterface::class, $this->backend);
    }
}
