<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Cache\Backend;

/**
 * What a {@see GenerationBackendInterface} call found and did.
 */
final readonly class GenerationResult
{
    /**
     * @param string|null $generation       the generation stored when the call returned; null when none is
     * @param bool        $written          whether this call stored the generation it was given
     * @param bool        $guardEntryExists whether the guard entry existed (false when none was named)
     */
    public function __construct(
        public ?string $generation,
        public bool $written,
        public bool $guardEntryExists,
    ) {}

    /** Whether an in-flight marker is stored. */
    public function isDirty(): bool
    {
        return null !== $this->generation && str_starts_with($this->generation, GenerationBackendInterface::DIRTY_PREFIX);
    }
}
