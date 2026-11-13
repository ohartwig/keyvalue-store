<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Cache\Backend;

use Moselwal\KeyValueStore\Cache\Backend\ConditionalReadBackendInterface;
use Moselwal\KeyValueStore\Cache\Backend\KeyValueBackend;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Conditional reads: which commands a read sends, and that every path that
 * cannot prove "unchanged" ends in a full read.
 */
#[RequiresPhpExtension('redis')]
final class KeyValueBackendConditionalReadTest extends TestCase
{
    #[Test]
    public function theBackendOffersConditionalReads(): void
    {
        self::assertInstanceOf(ConditionalReadBackendInterface::class, new KeyValueBackend(['hostname' => '127.0.0.1']));
    }

    #[Test]
    public function anUnchangedEntryIsOneScriptAndNoGet(): void
    {
        $redis = $this->redisWithoutSerializer();
        $backend = $this->buildBackend($redis, keyPrefix: 'typo3:c:');

        $redis->expects(self::once())
            ->method('eval')
            ->with(self::isString(), ['typo3:c:identData:menu', '4000', 'closing-bytes'], 1)
            ->willReturn(1);
        $redis->expects(self::never())->method('get');

        self::assertNull($backend->getUnlessUnchanged('menu', '4000:closing-bytes'));
    }

    #[Test]
    public function theClosingBytesMayContainTheSeparator(): void
    {
        $redis = $this->redisWithoutSerializer();
        $backend = $this->buildBackend($redis);

        $redis->expects(self::once())
            ->method('eval')
            ->with(self::isString(), ['identData:menu', '12', 'a:b:c'], 1)
            ->willReturn(1);

        self::assertNull($backend->getUnlessUnchanged('menu', '12:a:b:c'));
    }

    #[Test]
    public function aChangedEntryIsTheScriptsReplyWithoutASecondRoundTrip(): void
    {
        $redis = $this->redisWithoutSerializer();
        $backend = $this->buildBackend($redis);
        $stored = str_repeat('x', 300);

        $redis->expects(self::once())->method('eval')->willReturn($stored);
        $redis->expects(self::never())->method('get');

        $read = $backend->getUnlessUnchanged('menu', '4000:closing-bytes');
        self::assertNotNull($read);
        self::assertSame($stored, $read->payload);
        self::assertSame('300:' . str_repeat('x', 96), $read->validator);
    }

    #[Test]
    public function aMissingEntryIsFalseWithoutAValidator(): void
    {
        $redis = $this->redisWithoutSerializer();
        $backend = $this->buildBackend($redis);
        $redis->expects(self::once())->method('eval')->willReturn(0);

        $read = $backend->getUnlessUnchanged('menu', '4000:closing-bytes');
        self::assertNotNull($read);
        self::assertFalse($read->payload);
        self::assertNull($read->validator);
    }

    #[Test]
    public function aScriptErrorIsAFullReadAndEndsConditionalReadsOnThisInstance(): void
    {
        $redis = $this->redisWithoutSerializer();
        $backend = $this->buildBackend($redis);

        $redis->expects(self::once())->method('eval')->willReturn(false);
        $redis->expects(self::exactly(3))->method('get')->with('identData:menu')->willReturn('stored');

        $read = $backend->getUnlessUnchanged('menu', '4000:closing-bytes');
        self::assertNotNull($read);
        self::assertSame('stored', $read->payload);
        self::assertNull($read->validator, 'no validator: the reader stops asking');

        self::assertNull($backend->getWithValidator('menu')->validator);
        self::assertSame('stored', $backend->getUnlessUnchanged('menu', '6:stored')?->payload);
    }

    #[Test]
    public function aCompressedEntryIsDecompressedAndValidatedByItsStoredBytes(): void
    {
        $redis = $this->redisWithoutSerializer();
        $backend = $this->buildBackend($redis, compression: true);
        $payload = str_repeat('lorem ipsum ', 5000);
        $stored = (string) gzcompress($payload);

        $redis->expects(self::once())->method('get')->with('identData:page')->willReturn($stored);

        $read = $backend->getWithValidator('page');
        self::assertSame($payload, $read->payload);
        self::assertSame(\strlen($stored) . ':' . substr($stored, -200), $read->validator);
    }

    #[Test]
    public function withAPhpredisSerializerEveryReadIsThePlainGet(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('getOption')->with(\Redis::OPT_SERIALIZER)->willReturn(\Redis::SERIALIZER_PHP);
        $backend = $this->buildBackend($redis);

        $redis->expects(self::never())->method('eval');
        $redis->expects(self::exactly(2))->method('get')->with('identData:menu')->willReturn(['already' => 'decoded']);

        $first = $backend->getWithValidator('menu');
        self::assertSame(['already' => 'decoded'], $first->payload);
        self::assertNull($first->validator);
        self::assertSame(['already' => 'decoded'], $backend->getUnlessUnchanged('menu', '4000:closing-bytes')?->payload);
    }

    #[Test]
    public function aDisconnectedBackendAnswersLikeGet(): void
    {
        $redis = $this->createMock(\Redis::class);
        $backend = $this->buildBackend($redis, connected: false);
        $redis->expects(self::never())->method('eval');
        $redis->expects(self::never())->method('get');

        self::assertFalse($backend->getWithValidator('menu')->payload);
        self::assertFalse($backend->getUnlessUnchanged('menu', '4000:closing-bytes')?->payload);
    }

    private function redisWithoutSerializer(): \Redis&\PHPUnit\Framework\MockObject\MockObject
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('getOption')->with(\Redis::OPT_SERIALIZER)->willReturn(\Redis::SERIALIZER_NONE);

        return $redis;
    }

    private function buildBackend(\Redis $redis, string $keyPrefix = '', bool $compression = false, bool $connected = true): KeyValueBackend
    {
        $backend = new KeyValueBackend(['hostname' => '127.0.0.1', 'compression' => $compression]);

        $reflection = new \ReflectionClass($backend);
        $reflection->getProperty('redis')->setValue($backend, $redis);
        $reflection->getProperty('connected')->setValue($backend, $connected);
        if ('' !== $keyPrefix) {
            $reflection->getProperty('keyPrefix')->setValue($backend, $keyPrefix);
        }

        return $backend;
    }
}
