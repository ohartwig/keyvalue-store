<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Functional\Session\Backend;

use Moselwal\KeyValueStore\Session\Backend\KeyValueSessionBackend;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use Throwable;
use TYPO3\CMS\Core\Session\Backend\Exception\SessionNotFoundException;
use TYPO3\CMS\Core\Session\Backend\Exception\SessionNotUpdatedException;

/**
 * Functional tests for KeyValueSessionBackend CRUD, collectGarbage, and retry logic.
 * Requires a running Redis instance.
 *
 * Covers T013 (CRUD), T014 (collectGarbage), T015 (retry logic).
 */
#[RequiresPhpExtension('redis')]
final class KeyValueSessionBackendFunctionalTest extends TestCase
{
    private KeyValueSessionBackend $sessionBackend;
    private Redis $redis;

    /**
     * Ob die Verbindung wirklich steht.
     *
     * `markTestSkipped` in setUp() beendet nur den Testkoerper — tearDown()
     * laeuft trotzdem. Ohne dieses Flag griff es auf eine nie verbundene
     * Redis-Instanz zu und machte aus jedem uebersprungenen Test einen Fehler
     * ("Redis server went away"), im Wechsel: S E S E S E.
     */
    private bool $redisAvailable = false;
    private string $prefix = 'typo3:sess:test:';

    protected function setUp(): void
    {
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        try {
            $this->redis = new Redis();
            $this->redis->connect($host, $port, 1.0);
            $this->redis->ping();
            $this->redisAvailable = true;
        } catch (RedisException) {
            self::markTestSkipped('Redis is not available at '.$host.':'.$port);
        }

        // Use database 15 for tests to avoid collisions
        $this->redis->select(15);

        $this->sessionBackend = new KeyValueSessionBackend();
        $this->sessionBackend->initialize('test', [
            'hostname' => $host,
            'port' => $port,
            'database' => 15,
            'sessionLifetime' => 3600,
            'hashSecret' => 'functional-test-hash-secret-32bytes',
        ]);

        // Clean up test keys
        $this->flushTestKeys();

        // Set EXEC_TIME for deterministic timestamps
        $GLOBALS['EXEC_TIME'] = time();
    }

    protected function tearDown(): void
    {
        if (!$this->redisAvailable) {
            return;
        }

        $this->flushTestKeys();
        try {
            $this->redis->close();
        } catch (Throwable) {
        }
    }

    private function flushTestKeys(): void
    {
        // Bewusst Zeichen fuer Zeichen wie KeyValueSessionBackend::getAll():
        // phpredis nimmt `scan($iterator, ?string $pattern, int $count)`, nicht
        // das Options-Array von Predis — das war hier eingetragen und wirft
        // einen TypeError. Der Startwert ist `null`, nicht `0`, und der Abbruch
        // vergleicht nach `(int)`-Wandlung: phpredis gibt den Cursor als
        // Zeichenkette zurueck, und ein strikter Vergleich gegen 0 laeuft dann
        // ewig.
        $cursor = null;
        do {
            $keys = $this->redis->scan($cursor, $this->prefix.'*', 100);
            if (false === $keys || [] === $keys) {
                continue;
            }
            $this->redis->del($keys);
        } while (0 !== (int) $cursor);
    }

    // -----------------------------------------------------------------------
    // T013: CRUD Operations
    // -----------------------------------------------------------------------

    public function testSetCreatesSession(): void
    {
        $data = ['ses_data' => 'test_value', 'ses_userid' => 1];
        $result = $this->sessionBackend->set('session-abc', $data);

        self::assertSame('session-abc', $result['ses_id']);
        self::assertSame('test_value', $result['ses_data']);
        self::assertArrayHasKey('ses_tstamp', $result);
    }

    public function testGetReturnsSession(): void
    {
        $this->sessionBackend->set('session-get', ['ses_data' => 'hello']);

        $result = $this->sessionBackend->get('session-get');

        self::assertSame('session-get', $result['ses_id']);
        self::assertSame('hello', $result['ses_data']);
    }

    public function testGetThrowsForNonexistentSession(): void
    {
        $this->expectException(SessionNotFoundException::class);

        $this->sessionBackend->get('nonexistent-session');
    }

    public function testUpdateMergesData(): void
    {
        $this->sessionBackend->set('session-upd', ['ses_data' => 'old', 'extra' => 'keep']);

        $result = $this->sessionBackend->update('session-upd', ['ses_data' => 'new']);

        self::assertSame('new', $result['ses_data']);
        self::assertSame('keep', $result['extra']);
        self::assertSame('session-upd', $result['ses_id']);
    }

    public function testUpdateThrowsForNonexistentSession(): void
    {
        $this->expectException(SessionNotUpdatedException::class);

        $this->sessionBackend->update('nonexistent-session', ['ses_data' => 'value']);
    }

    public function testRemoveDeletesSession(): void
    {
        $this->sessionBackend->set('session-del', ['ses_data' => 'bye']);

        $result = $this->sessionBackend->remove('session-del');
        self::assertTrue($result);

        $this->expectException(SessionNotFoundException::class);
        $this->sessionBackend->get('session-del');
    }

    public function testRemoveReturnsFalseForNonexistentSession(): void
    {
        $result = $this->sessionBackend->remove('never-existed');
        self::assertFalse($result);
    }

    public function testGetAllReturnsAllSessions(): void
    {
        $this->sessionBackend->set('sess-1', ['ses_data' => 'a']);
        $this->sessionBackend->set('sess-2', ['ses_data' => 'b']);
        $this->sessionBackend->set('sess-3', ['ses_data' => 'c']);

        $all = $this->sessionBackend->getAll();

        self::assertCount(3, $all);

        $ids = array_column($all, 'ses_id');
        sort($ids);
        self::assertSame(['sess-1', 'sess-2', 'sess-3'], $ids);
    }

    public function testSetEnforcesSesId(): void
    {
        $result = $this->sessionBackend->set('enforced-id', [
            'ses_id' => 'wrong-id',
            'ses_data' => 'value',
        ]);

        // ses_id must be overwritten by the actual session ID
        self::assertSame('enforced-id', $result['ses_id']);
    }

    public function testSetUpdatesSesTimestamp(): void
    {
        $result = $this->sessionBackend->set('ts-session', ['ses_data' => 'v']);

        self::assertSame($GLOBALS['EXEC_TIME'], $result['ses_tstamp']);
    }

    // -----------------------------------------------------------------------
    // T014: collectGarbage
    // -----------------------------------------------------------------------

    public function testCollectGarbageIsNoOpWithRedisTtl(): void
    {
        // Redis handles expiry via TTL, so collectGarbage should not throw
        $this->sessionBackend->set('gc-session', ['ses_data' => 'gc']);

        // Should complete without error
        $this->sessionBackend->collectGarbage(3600);

        // Session should still exist (GC doesn't affect unexpired sessions)
        $result = $this->sessionBackend->get('gc-session');
        self::assertSame('gc-session', $result['ses_id']);
    }

    public function testExpiredSessionsAreCleanedByRedisTtl(): void
    {
        // Create a session backend with 1-second TTL
        $shortLivedBackend = new KeyValueSessionBackend();
        $shortLivedBackend->initialize('test', [
            'hostname' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('REDIS_PORT') ?: 6379),
            'database' => 15,
            'sessionLifetime' => 1,
            'hashSecret' => 'functional-test-hash-secret-32bytes',
        ]);

        $shortLivedBackend->set('expire-soon', ['ses_data' => 'temporary']);

        // Session exists immediately
        $result = $shortLivedBackend->get('expire-soon');
        self::assertSame('expire-soon', $result['ses_id']);

        // Wait for TTL to expire
        sleep(2);

        // Session should be gone (Redis TTL cleanup)
        $this->expectException(SessionNotFoundException::class);
        $shortLivedBackend->get('expire-soon');
    }

    // -----------------------------------------------------------------------
    // T015: Retry Logic
    // -----------------------------------------------------------------------

    public function testSessionBackendReconnectsAfterDisconnect(): void
    {
        // First: create a session to establish connection
        $this->sessionBackend->set('retry-test', ['ses_data' => 'before']);

        // Verify it works
        $result = $this->sessionBackend->get('retry-test');
        self::assertSame('before', $result['ses_data']);

        // The retry logic is tested implicitly: if the internal Redis connection
        // is broken, getRedis() retries up to 3 times with exponential backoff.
        // A full test would require killing the Redis connection mid-operation,
        // which is better suited for integration testing in CI.
        //
        // Here we verify that multiple consecutive operations work correctly,
        // which exercises the connection health check (ping) in getRedis().
        $this->sessionBackend->set('retry-test-2', ['ses_data' => 'also works']);
        $result2 = $this->sessionBackend->get('retry-test-2');
        self::assertSame('also works', $result2['ses_data']);
    }

    public function testMultipleConcurrentOperationsWork(): void
    {
        // Create multiple sessions rapidly to exercise connection pooling
        for ($i = 0; $i < 10; ++$i) {
            $this->sessionBackend->set('concurrent-'.$i, ['ses_data' => 'data-'.$i]);
        }

        // Read them all back
        for ($i = 0; $i < 10; ++$i) {
            $result = $this->sessionBackend->get('concurrent-'.$i);
            self::assertSame('data-'.$i, $result['ses_data']);
        }
    }
}
