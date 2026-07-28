<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Connection;

/**
 * Builds a connected \Redis handle from a backend's options.
 *
 * The cache and session backends need exactly this one operation, and naming
 * it separately is what lets them be tested at all: KeyValueConnectionFactory
 * is final — deliberately, it is not built for inheritance — and a final class
 * cannot be doubled. Without an abstraction here, every test that wants to
 * observe what the backends ask for, or to simulate a connection that fails,
 * has to open a real socket.
 *
 * That is not hypothetical. The tests covering initializeObject() and the
 * session retry/backoff path were doing precisely that and had been erroring
 * out for some time, unseen, because the pipeline job that reports it carried
 * allow_failure: true.
 */
interface ConnectionFactoryInterface
{
    /**
     * @param array<string, mixed> $options backend options, already normalised
     *
     * @throws \RedisException when the connection cannot be established
     */
    public function create(array $options): \Redis;
}
