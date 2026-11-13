<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Cache\Backend;

/**
 * What a {@see ConditionalReadBackendInterface} read returns: the payload as
 * get() returns it, and the validator for the stored bytes it came from.
 */
final readonly class ConditionalRead
{
    /**
     * @param mixed       $payload   what get() returns: the entry's data, false when there is none
     * @param string|null $validator for the next getUnlessUnchanged(); null when none can be given
     */
    public function __construct(
        public mixed $payload,
        public ?string $validator,
    ) {}
}
