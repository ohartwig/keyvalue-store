<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Tests\Unit\Session\Backend\Fixtures;

/**
 * Just enough of \Redis for the session backend, keeping what is written.
 *
 * @internal
 */
final class InMemoryRedis extends \Redis
{
    /** @var array<string, string> */
    public array $data = [];
    public ?\RedisException $failWith = null;
    public bool $refuseWrites = false;
    /** Every compare-and-set finds the key changed, as if another request always wrote first. */
    public bool $loseCompareAndSet = false;

    /** @var list<array{string, list<mixed>, int}> */
    public array $evalCalls = [];

    public function __construct()
    {
        // No connection.
    }

    public function ping(?string $message = null): \Redis|string|bool
    {
        $this->fail();

        return true;
    }

    public function get(string $key): mixed
    {
        $this->fail();

        return $this->data[$key] ?? false;
    }

    public function setex(string $key, int $expire, mixed $value): \Redis|bool
    {
        $this->fail();
        if ($this->refuseWrites) {
            return false;
        }
        $this->data[$key] = (string) $value;

        return true;
    }

    public function del(array|string $key, string ...$other_keys): \Redis|int|false
    {
        $this->fail();
        $deleted = 0;
        foreach (array_merge((array) $key, $other_keys) as $k) {
            if (isset($this->data[$k])) {
                unset($this->data[$k]);
                ++$deleted;
            }
        }

        return $deleted;
    }

    public function scan(int|string|null &$iterator, ?string $pattern = null, int $count = 0, ?string $type = null): array|false
    {
        $this->fail();
        $iterator = 0;
        $prefix = rtrim((string) $pattern, '*');

        return array_values(array_filter(array_keys($this->data), static fn(string $k): bool => str_starts_with($k, $prefix)));
    }

    public function mget(array $keys): \Redis|array|false
    {
        $this->fail();

        return array_map(fn(string $k): string|false => $this->data[$k] ?? false, $keys);
    }

    /** Compare-and-set, the only script the session backend runs with one key. */
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        $this->fail();
        $this->evalCalls[] = [$script, array_values($args), $num_keys];
        [$key, $expected, $new] = $args + [null, null, null];
        if ($this->loseCompareAndSet) {
            return 0;
        }
        if (1 === $num_keys && is_string($key) && ($this->data[$key] ?? null) === $expected) {
            $this->data[$key] = (string) $new;

            return 1;
        }

        return 0;
    }

    private function fail(): void
    {
        if (null !== $this->failWith) {
            throw $this->failWith;
        }
    }
}
