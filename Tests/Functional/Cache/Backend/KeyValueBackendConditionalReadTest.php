<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Functional\Cache\Backend;

use Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * Conditional reads against a real server: an unchanged entry is confirmed
 * without the server sending it (no GET in its command statistics), every
 * change is seen, with and without compression. Requires a running
 * Redis/Valkey.
 */
#[RequiresPhpExtension('redis')]
final class KeyValueBackendConditionalReadTest extends TestCase
{
    private \Redis $redis;

    private string $host;

    private int $port;

    /** @var list<KeyValueBackend> */
    private array $backends = [];

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
        $this->backend()->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->backends as $backend) {
            try {
                $backend->flush();
            } catch (\Throwable) {
            }
        }
    }

    /** @param array<string, mixed> $options */
    private function backend(array $options = []): KeyValueBackend
    {
        $backend = new KeyValueBackend($options + ['hostname' => $this->host, 'port' => $this->port, 'database' => 15]);
        $backend->setCache(new VariableFrontend('test_cache', $backend));
        $backend->initializeObject();
        $this->backends[] = $backend;

        return $backend;
    }

    /** A payload as VariableFrontend stores it: serialised data, then 96 hex digits of HMAC. */
    private static function payload(string $content, int $bytes = 40000): string
    {
        $serialized = serialize(str_pad($content, $bytes, 'lorem ipsum '));

        return $serialized . hash_hmac('sha3-384', $serialized, 'test-key');
    }

    /** How often the server ran GET so far (GETRANGE is counted apart). */
    private function fullReads(): int
    {
        $stats = $this->redis->info('commandstats');

        return 1 === preg_match('/calls=(\d+)/', (string) ($stats['cmdstat_get'] ?? ''), $match) ? (int) $match[1] : 0;
    }

    public function testAnUnchangedEntryIsConfirmedWithoutTheServerSendingIt(): void
    {
        $backend = $this->backend();
        $payload = self::payload('menu');
        $backend->set('menu', $payload);

        $first = $backend->getWithValidator('menu');
        self::assertSame($payload, $first->payload);
        self::assertSame(\strlen($payload) . ':' . substr($payload, -96), $first->validator);

        $readsBefore = $this->fullReads();
        self::assertNull($backend->getUnlessUnchanged('menu', $first->validator));
        self::assertNull($backend->getUnlessUnchanged('menu', $first->validator));
        self::assertSame($readsBefore, $this->fullReads(), 'no GET for an unchanged entry');
    }

    public function testAChangedEntryComesBackInTheSameCallWithItsNewValidator(): void
    {
        $backend = $this->backend();
        $backend->set('menu', self::payload('one'));
        $first = $backend->getWithValidator('menu');
        self::assertIsString($first->validator);

        $changed = self::payload('two');
        $this->backend()->set('menu', $changed);

        $second = $backend->getUnlessUnchanged('menu', $first->validator);
        self::assertNotNull($second);
        self::assertSame($changed, $second->payload);
        self::assertIsString($second->validator);
        self::assertNotSame($first->validator, $second->validator);
        self::assertNull($backend->getUnlessUnchanged('menu', $second->validator));
    }

    public function testTheSameLengthWithOtherClosingBytesIsAChange(): void
    {
        $backend = $this->backend();
        $backend->set('menu', self::payload('aaaa'));
        $first = $backend->getWithValidator('menu');
        self::assertIsString($first->validator);
        $changed = self::payload('bbbb');
        self::assertSame(\strlen(self::payload('aaaa')), \strlen($changed));
        $backend->set('menu', $changed);

        self::assertSame($changed, $backend->getUnlessUnchanged('menu', $first->validator)?->payload);
    }

    public function testARemovedAFlushedAndAnExpiredEntryAreGone(): void
    {
        $backend = $this->backend();
        foreach (['removed', 'flushed', 'tagged', 'expired'] as $identifier) {
            $backend->set($identifier, self::payload($identifier), 'tagged' === $identifier ? ['t'] : []);
        }
        $validators = [];
        foreach (['removed', 'flushed', 'tagged', 'expired'] as $identifier) {
            $validators[$identifier] = (string) $backend->getWithValidator($identifier)->validator;
        }

        $backend->flushByTag('t');
        $gone = $backend->getUnlessUnchanged('tagged', $validators['tagged']);
        self::assertNotNull($gone);
        self::assertFalse($gone->payload);
        self::assertNull($gone->validator);

        $backend->remove('removed');
        self::assertFalse($backend->getUnlessUnchanged('removed', $validators['removed'])?->payload);

        $this->redis->del('identData:expired');
        self::assertFalse($backend->getUnlessUnchanged('expired', $validators['expired'])?->payload);

        $backend->flush();
        self::assertFalse($backend->getUnlessUnchanged('flushed', $validators['flushed'])?->payload);
    }

    public function testACompressedCacheComparesWhatIsStoredNotThePayload(): void
    {
        $backend = $this->backend(['compression' => true]);
        $payload = self::payload('compressed');
        $backend->set('page', $payload);
        $stored = (string) $this->redis->get('identData:page');
        self::assertNotSame($payload, $stored, 'the server holds the zlib stream');

        $first = $backend->getWithValidator('page');
        self::assertSame($payload, $first->payload);
        self::assertSame(\strlen($stored) . ':' . substr($stored, -200), $first->validator);

        $readsBefore = $this->fullReads();
        self::assertNull($backend->getUnlessUnchanged('page', $first->validator));
        self::assertSame($readsBefore, $this->fullReads());

        // Same length, only the HMAC differs: the zlib stream ends differently.
        $changed = substr($payload, 0, -96) . hash_hmac('sha3-384', 'something else', 'test-key');
        $backend->set('page', $changed);
        $second = $backend->getUnlessUnchanged('page', $first->validator);
        self::assertNotNull($second);
        self::assertSame($changed, $second->payload);
        self::assertNull($backend->getUnlessUnchanged('page', (string) $second->validator));
    }

    public function testAValidatorFromAnUncompressedWriterDoesNotMatchACompressedEntry(): void
    {
        $payload = self::payload('rolling');
        $plain = $this->backend();
        $plain->set('x', $payload);
        $validator = (string) $plain->getWithValidator('x')->validator;

        $compressed = $this->backend(['compression' => true]);
        $compressed->set('x', $payload);

        self::assertSame($payload, $compressed->getUnlessUnchanged('x', $validator)?->payload);
    }

    public function testAnEntryShorterThanTheClosingBytesIsComparedWhole(): void
    {
        $backend = $this->backend();
        $backend->set('small', 'abc');
        $first = $backend->getWithValidator('small');
        self::assertSame('3:abc', $first->validator);
        self::assertNull($backend->getUnlessUnchanged('small', '3:abc'));

        $backend->set('small', 'abd');
        self::assertSame('abd', $backend->getUnlessUnchanged('small', '3:abc')?->payload);
    }

    public function testAValueThatLooksLikeAReplyCodeIsStillAValue(): void
    {
        $backend = $this->backend();
        $backend->set('one', '1');
        $backend->set('zero', '0');

        self::assertSame('1', $backend->getUnlessUnchanged('one', '5:other')?->payload);
        self::assertSame('0', $backend->getUnlessUnchanged('zero', '5:other')?->payload);
    }

    public function testAMissingAndAnEmptyEntryGetNoValidator(): void
    {
        $backend = $this->backend();
        $missing = $backend->getWithValidator('nothing');
        self::assertFalse($missing->payload);
        self::assertNull($missing->validator);

        $backend->set('empty', '');
        $empty = $backend->getWithValidator('empty');
        self::assertSame('', $empty->payload);
        self::assertNull($empty->validator);
    }

    public function testAForeignValidatorIsAFullRead(): void
    {
        $backend = $this->backend();
        $payload = self::payload('menu');
        $backend->set('menu', $payload);

        foreach (['', 'no-separator', ':tail', '12:', '-3:abc', '0:abc', '1e3:abc'] as $validator) {
            $read = $backend->getUnlessUnchanged('menu', $validator);
            self::assertNotNull($read, $validator);
            self::assertSame($payload, $read->payload, $validator);
            self::assertSame(\strlen($payload) . ':' . substr($payload, -96), $read->validator, $validator);
        }
    }

    public function testWithAPhpredisSerializerThereAreNoConditionalReads(): void
    {
        $backend = $this->backend(['serializer' => 'php']);
        $this->redis->set('identData:x', serialize('value'));

        $read = $backend->getWithValidator('x');
        self::assertSame($backend->get('x'), $read->payload);
        self::assertNull($read->validator);
        self::assertSame($backend->get('x'), $backend->getUnlessUnchanged('x', '5:value')?->payload);
    }
}
