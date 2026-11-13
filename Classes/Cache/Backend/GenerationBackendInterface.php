<?php

declare(strict_types=1);

namespace Moselwal\KeyValueStore\Cache\Backend;

/**
 * A cache backend that keeps named generation values next to its entries
 * and can store an entry and move a generation in one atomic step.
 *
 * A generation is one opaque string per name. Writers replace it with a
 * fresh random value whenever they change entries; a reader that remembers
 * the value it last saw learns with one read whether anything changed. It
 * is for readers that keep entries in memory across requests.
 *
 * Format, owned by the backend:
 *
 * - the value is stored raw: never serialized, signed or compressed, in a
 *   key of its own outside the entry key space (it is not an entry: get(),
 *   has(), remove() and the tag methods never see it). flush() removes it
 *   with everything else; flushByTag(s)() and collectGarbage() leave it.
 * - it has no expiry.
 * - a value starting with {@see DIRTY_PREFIX} is an in-flight marker: its
 *   writer is in the middle of a change that takes more than one step and
 *   will publish a clean value when it is done. Readers must not trust what
 *   they remembered while a marker is stored. Only the writer that wrote a
 *   marker replaces it with a clean value: setWithGeneration() and
 *   publishGeneration() leave a foreign marker untouched. Otherwise a
 *   writer whose final write fails would leave a clean generation over its
 *   half-applied change.
 *
 * Guard entry: every method takes the identifier of an ordinary cache entry
 * of this backend whose existence means "writers that do not know this
 * generation are active" (older releases that keep their generation in an
 * entry). setWithGeneration() does nothing while it exists, so the caller
 * can take the path those writers understand; the other methods report it.
 * The check costs no extra round trip.
 *
 * Every method is one round trip and throws when the server cannot run it;
 * nothing is retried or silently skipped.
 */
interface GenerationBackendInterface
{
    /** Prefix of an in-flight marker. */
    public const string DIRTY_PREFIX = 'dirty-';

    /**
     * The stored generation (null: none) and whether the guard entry exists.
     */
    public function readGeneration(string $name, ?string $guardEntryIdentifier = null): GenerationResult;

    /**
     * Stores $generation unconditionally: the way to place an in-flight
     * marker before a change of several steps.
     */
    public function writeGeneration(string $name, string $generation, ?string $guardEntryIdentifier = null): GenerationResult;

    /**
     * Stores the clean $generation unless an in-flight marker other than
     * $ownMarker is stored (`written` is then false: that writer publishes).
     * Check and write are one step on the server.
     */
    public function publishGeneration(string $name, string $generation, string $ownMarker, ?string $guardEntryIdentifier = null): GenerationResult;

    /**
     * Runs $write and carries out the one set() it issues on this backend
     * (directly or through the cache frontend, which keeps its own encoding)
     * together with the generation write, atomically:
     *
     * - guard entry exists: nothing is stored, `guardEntryExists` is true;
     * - an in-flight marker is stored: the entry is stored, the marker
     *   stays, `written` is false;
     * - otherwise the entry is stored and the generation becomes
     *   $generation, `written` is true.
     *
     * @param \Closure(): void $write must issue exactly one set() on this backend
     *
     * @throws \LogicException when $write issued no set() on this backend
     */
    public function setWithGeneration(string $name, string $generation, \Closure $write, ?string $guardEntryIdentifier = null): GenerationResult;
}
