<?php

namespace justinholtweb\caffeine\publish;

/**
 * Where published artifacts are written.
 *
 * Deliberately smaller than Craft's filesystem interface. Publishing needs five operations, and
 * narrowing to them is what lets the local implementation guarantee an atomic write — something
 * the general interface cannot promise across every backend.
 */
interface StoreInterface
{
    /**
     * Writes a file, replacing any existing one.
     *
     * Implementations must make this atomic from a reader's point of view: a concurrent request
     * sees either the whole old file or the whole new one, never a partial write. The stable
     * pointer is rewritten on every publish while visitors are reading it, so a torn write here
     * is a hard error on a live page.
     */
    public function write(string $path, string $contents): void;

    public function exists(string $path): bool;

    /** Contents of a published file, or null when it is not there. */
    public function read(string $path): ?string;

    /** Silently does nothing when the file is already gone — pruning races with itself. */
    public function delete(string $path): void;

    /** Public URL of a published file, or null when the store is not web-accessible. */
    public function url(string $path): ?string;

    /** Human-readable location, for the CP and console output. */
    public function describe(): string;
}
