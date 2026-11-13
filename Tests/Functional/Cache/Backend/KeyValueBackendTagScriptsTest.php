<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Functional\Cache\Backend;

use Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * flushByTag(s) and remove() as server-side scripts: the same keyspace as the
 * released multi-round-trip paths leave behind, and the fallback for flushes
 * too large for one script. Requires a running Redis/Valkey.
 */
#[RequiresPhpExtension('redis')]
final class KeyValueBackendTagScriptsTest extends TestCase
{
    private KeyValueBackend $backend;

    private \Redis $redis;

    protected function setUp(): void
    {
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);
        try {
            $this->redis = new \Redis();
            $this->redis->connect($host, $port, 1.0);
            $this->redis->select(15);
        } catch (\RedisException) {
            self::markTestSkipped('Redis is not available at ' . $host . ':' . $port);
        }
        $this->backend = new KeyValueBackend(['hostname' => $host, 'port' => $port, 'database' => 15]);
        $this->backend->setCache(new VariableFrontend('test_cache', $this->backend));
        $this->backend->initializeObject();
        $this->backend->flush();
    }

    protected function tearDown(): void
    {
        try {
            $this->backend->flush();
        } catch (\Throwable) {
        }
    }

    /** @return list<string> */
    private function members(string $tag): array
    {
        $members = $this->redis->sMembers('tagIdents:' . $tag);
        sort($members);

        return $members;
    }

    public function testAFlushTakesTheEntriesOutOfTheirOtherTags(): void
    {
        $this->backend->set('a', 'A', ['page_1', 'ns']);
        $this->backend->set('b', 'B', ['page_1', 'ns']);
        $this->backend->set('c', 'C', ['page_2', 'ns']);

        $this->backend->flushByTag('page_1');

        self::assertFalse($this->backend->has('a'));
        self::assertFalse($this->backend->has('b'));
        self::assertSame('C', $this->backend->get('c'));
        self::assertSame(['c'], $this->members('ns'), 'a and b left the shared tag');
        self::assertSame([], $this->members('page_1'), 'the flushed tag set is gone');
        self::assertSame(0, $this->redis->exists('identTags:a', 'identTags:b'));
        self::assertSame(['ns', 'page_2'], (function (): array {
            $t = $this->redis->sMembers('identTags:c');
            sort($t);

            return $t;
        })());
    }

    public function testFlushByTagsTakesSeveralTagsAtOnceAndAnEmptyTagIsHarmless(): void
    {
        $this->backend->set('a', 'A', ['x']);
        $this->backend->set('b', 'B', ['y', 'z']);
        $this->backend->set('c', 'C', ['z']);

        $this->backend->flushByTags(['x', 'y', 'never-used']);

        self::assertFalse($this->backend->has('a'));
        self::assertFalse($this->backend->has('b'));
        self::assertTrue($this->backend->has('c'));
        self::assertSame(['c'], $this->members('z'));
    }

    public function testAFlushTooLargeForOneScriptTakesTheReleasedPathWithTheSameResult(): void
    {
        for ($i = 0; $i < 1005; ++$i) {
            $this->backend->set('e' . $i, 'v', ['big', 'shared']);
        }
        $this->backend->set('keep', 'K', ['shared']);

        $this->backend->flushByTag('big');

        self::assertFalse($this->backend->has('e0'));
        self::assertFalse($this->backend->has('e1004'));
        self::assertSame('K', $this->backend->get('keep'));
        self::assertSame(['keep'], $this->members('shared'));
        self::assertSame([], $this->members('big'));
    }

    public function testRemoveReportsWhetherTheEntryExistedAndCleansItsTags(): void
    {
        $this->backend->set('a', 'A', ['t1', 't2']);
        $this->backend->set('b', 'B', ['t1']);

        self::assertTrue($this->backend->remove('a'));
        self::assertFalse($this->backend->remove('a'), 'gone already');
        self::assertSame(['b'], $this->members('t1'));
        self::assertSame([], $this->members('t2'));
        self::assertSame(0, $this->redis->exists('identData:a', 'identTags:a'));
    }

    public function testRemoveOfAnExpiredEntryClearsItsLeftoverTagIndex(): void
    {
        $this->backend->set('gone', 'G', ['t']);
        $this->redis->del('identData:gone');

        self::assertFalse($this->backend->remove('gone'), 'the data key did not exist');
        self::assertSame([], $this->members('t'), 'the leftover relation is gone too');
        self::assertSame(0, $this->redis->exists('identTags:gone'));
    }
}
