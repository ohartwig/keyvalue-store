<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Session\Backend;

use Moselwal\KeyValueStore\Session\Backend\KeyValueSessionBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use Moselwal\KeyValueStore\Tests\Unit\Session\Backend\Fixtures\InMemoryRedis;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Session\Backend\Exception\SessionNotCreatedException;
use TYPO3\CMS\Core\Session\Backend\Exception\SessionNotFoundException;
use TYPO3\CMS\Core\Session\Backend\Exception\SessionNotUpdatedException;
use TYPO3\CMS\Core\Session\Backend\HashableSessionBackendInterface;

/**
 * The plain session ID is the cookie value. It must not leave the request.
 *
 * AUD-077-F01: the record was keyed by the HMAC of the session ID, but
 * set() and update() wrote the plain ID into `ses_id` inside the value.
 * Anyone reading the value, and core's Backend users module (which renders
 * `ses_id` from getAll() into the page, believing it is the hash), got the
 * cookie of every active session.
 *
 * AUD-077-F02: every session exception message carried the plain ID, and
 * core's production exception handler logs the message of the uncaught
 * SessionNotCreatedException / SessionNotUpdatedException.
 *
 * The store is an in-memory \Redis double that keeps exactly what the
 * backend writes, so "the ID is not persisted" is asserted on the bytes.
 */
#[RequiresPhpExtension('redis')]
final class KeyValueSessionBackendSessionIdExposureTest extends TestCase
{
    /** 40 hex characters, the shape core generates. */
    private const SESSION_ID = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4';
    private const SECRET = 'test-fixture-encryption-key-32bytes';
    private const PREFIX = 'typo3:sess:be:';

    #[Test]
    public function setPersistsTheSessionIdNeitherInTheKeyNorInTheValue(): void
    {
        $store = new InMemoryRedis();
        $backend = $this->backendWith($store);

        $record = $backend->set(self::SESSION_ID, ['ses_userid' => 1, 'ses_data' => 'a:0:{}']);

        self::assertCount(1, $store->data);
        $this->assertStoreDoesNotContainTheSessionId($store);
        self::assertSame($this->hmac(), $record['ses_id'], 'set() must return the hash as ses_id, like core');
    }

    #[Test]
    public function updatePersistsTheSessionIdNeitherInTheKeyNorInTheValue(): void
    {
        $store = new InMemoryRedis();
        $backend = $this->backendWith($store);
        $backend->set(self::SESSION_ID, ['ses_userid' => 1]);

        $record = $backend->update(self::SESSION_ID, ['ses_data' => 'a:1:{s:1:"x";i:1;}', 'ses_id' => self::SESSION_ID]);

        $this->assertStoreDoesNotContainTheSessionId($store);
        self::assertSame($this->hmac(), $record['ses_id']);
    }

    #[Test]
    public function getAllReportsTheHashAsSesIdSoTheBackendUsersModuleNeverSeesTheCookie(): void
    {
        $store = new InMemoryRedis();
        $backend = $this->backendWith($store);
        $backend->set(self::SESSION_ID, ['ses_userid' => 1]);

        $all = $backend->getAll();

        self::assertCount(1, $all);
        self::assertNotSame(self::SESSION_ID, $all[0]['ses_id']);
        self::assertStringNotContainsString(self::SESSION_ID, (string) json_encode($all));
        // The Online users view marks the viewer's own row by comparing
        // ses_id against hash(own id); the badge must keep matching.
        self::assertInstanceOf(HashableSessionBackendInterface::class, $backend);
        self::assertSame($backend->hash(self::SESSION_ID), $all[0]['ses_id']);
    }

    #[Test]
    public function lookupsByThePlainSessionIdStillWork(): void
    {
        $store = new InMemoryRedis();
        $backend = $this->backendWith($store);
        $backend->set(self::SESSION_ID, ['ses_userid' => 7]);

        $record = $backend->get(self::SESSION_ID);

        self::assertSame(7, $record['ses_userid']);
        self::assertSame($this->hmac(), $record['ses_id']);
    }

    /**
     * Upgrade path (dual read): records written before the fix sit under
     * the same hashed key but carry the plain ID in the value. They stay
     * readable, are reported with the hash, and are rewritten on the first
     * read so the plain ID leaves the store without waiting for the TTL.
     */
    #[Test]
    public function aRecordWrittenBeforeTheFixIsReadableAndMigratedOnRead(): void
    {
        $store = new InMemoryRedis();
        $store->data[self::PREFIX . $this->hmac()] = (string) json_encode([
            'ses_id' => self::SESSION_ID,
            'ses_userid' => 3,
            'ses_tstamp' => 1,
        ]);
        $backend = $this->backendWith($store);

        $all = $backend->getAll();
        self::assertSame($this->hmac(), $all[0]['ses_id'], 'getAll() must not hand out the legacy plain ses_id');

        $record = $backend->get(self::SESSION_ID);
        self::assertSame(3, $record['ses_userid']);
        self::assertSame($this->hmac(), $record['ses_id']);
        $this->assertStoreDoesNotContainTheSessionId($store);
    }

    /**
     * Core hands the hash from getAll() back to remove() (Backend users
     * "end session", SessionManager::invalidateAllSessionsByUserId()); the
     * logout path hands the plain ID. Both must delete the record.
     */
    #[Test]
    public function removeAcceptsThePlainIdAndTheHashedId(): void
    {
        $store = new InMemoryRedis();
        $backend = $this->backendWith($store);

        $backend->set(self::SESSION_ID, ['ses_userid' => 1]);
        self::assertTrue($backend->remove(self::SESSION_ID));
        self::assertSame([], $store->data);

        $backend->set(self::SESSION_ID, ['ses_userid' => 1]);
        self::assertTrue($backend->remove($backend->getAll()[0]['ses_id']));
        self::assertSame([], $store->data);
    }

    /**
     * @return iterable<string, array{\Closure(KeyValueSessionBackend): mixed, \Closure(): \Redis}>
     */
    public static function failingSessionCalls(): iterable
    {
        $broken = static function (): \Redis {
            $redis = new InMemoryRedis();
            $redis->failWith = new \RedisException('went away');

            return $redis;
        };

        yield 'get, Redis error' => [static fn(KeyValueSessionBackend $b) => $b->get(self::SESSION_ID), $broken];
        yield 'get, not found' => [static fn(KeyValueSessionBackend $b) => $b->get(self::SESSION_ID), static fn(): \Redis => new InMemoryRedis()];
        yield 'set, Redis error' => [static fn(KeyValueSessionBackend $b) => $b->set(self::SESSION_ID, []), $broken];
        yield 'set, write refused' => [static fn(KeyValueSessionBackend $b) => $b->set(self::SESSION_ID, []), static function (): \Redis {
            $redis = new InMemoryRedis();
            $redis->refuseWrites = true;

            return $redis;
        }];
        yield 'update, Redis error' => [static fn(KeyValueSessionBackend $b) => $b->update(self::SESSION_ID, []), $broken];
        yield 'update, not found' => [static fn(KeyValueSessionBackend $b) => $b->update(self::SESSION_ID, []), static fn(): \Redis => new InMemoryRedis()];
        yield 'update, lost the race twice' => [static fn(KeyValueSessionBackend $b) => $b->update(self::SESSION_ID, []), static function (): \Redis {
            $redis = new InMemoryRedis();
            $redis->data[self::PREFIX . hash_hmac('sha256', self::SESSION_ID, self::SECRET)] = '{}';
            $redis->abortTransactions = true;

            return $redis;
        }];
    }

    /**
     * @param \Closure(KeyValueSessionBackend): mixed $call
     * @param \Closure(): \Redis                      $redis
     */
    #[Test]
    #[DataProvider('failingSessionCalls')]
    public function noSessionExceptionMessageCarriesTheSessionId(\Closure $call, \Closure $redis): void
    {
        $backend = $this->backendWith($redis());

        try {
            $call($backend);
            self::fail('a session exception was expected');
        } catch (SessionNotFoundException|SessionNotCreatedException|SessionNotUpdatedException $e) {
            self::assertStringNotContainsString(self::SESSION_ID, $e->getMessage());
            self::assertStringNotContainsString($this->hmac(), $e->getMessage());
        }
    }

    private function assertStoreDoesNotContainTheSessionId(InMemoryRedis $store): void
    {
        foreach ($store->data as $key => $value) {
            self::assertStringNotContainsString(self::SESSION_ID, $key, 'the key carries the cookie');
            self::assertStringNotContainsString(self::SESSION_ID, $value, 'the value carries the cookie');
            $decoded = json_decode($value, true);
            self::assertIsArray($decoded);
            self::assertSame($this->hmac(), $decoded['ses_id'] ?? null, 'ses_id must be the HMAC, as in core');
        }
    }

    private function hmac(): string
    {
        return hash_hmac('sha256', self::SESSION_ID, self::SECRET);
    }

    private function backendWith(\Redis $redis): KeyValueSessionBackend
    {
        $backend = new KeyValueSessionBackend();
        $backend->initialize('BE', [
            'hostname' => 'irrelevant.test',
            'prefix' => self::PREFIX,
            'hashSecret' => self::SECRET,
        ]);
        (new \ReflectionProperty($backend, 'redis'))->setValue($backend, $redis);

        return $backend;
    }
}
