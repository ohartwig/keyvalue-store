<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Cache\Backend;

/**
 * A cache backend that can tell a reader whether an entry still holds the
 * bytes the reader already has, without sending those bytes again.
 *
 * For readers that keep a verified copy of an entry in memory (a FrankenPHP
 * worker memo) and only need the backend to say whether that copy is still
 * current. The backend is asked on every read, as before; what changes is the
 * answer to "still the same": a few bytes instead of the whole entry.
 *
 * A validator is issued by the backend for the bytes it stores and is opaque
 * to the reader: keep it next to the copy, hand it back with the next read.
 * It identifies stored bytes, not an entry: it carries no secret, and a
 * validator for other bytes only costs a full read.
 */
interface ConditionalReadBackendInterface
{
    /**
     * get(), plus a validator for the stored bytes it returned.
     *
     * The validator is null when the backend cannot offer conditional reads
     * for this cache (or the entry is missing); the payload is then exactly
     * what get() returns.
     */
    public function getWithValidator(string $entryIdentifier): ConditionalRead;

    /**
     * Null when the entry still holds the bytes $validator was issued for.
     * Otherwise what getWithValidator() returns at that moment: the entry's
     * current payload (false when it is gone) and its new validator.
     */
    public function getUnlessUnchanged(string $entryIdentifier, string $validator): ?ConditionalRead;
}
