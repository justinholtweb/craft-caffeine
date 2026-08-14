<?php

namespace justinholtweb\caffeine\publish;

use craft\base\FsInterface;
use Throwable;

/**
 * Publishes to a Craft filesystem — S3, a CDN-backed volume, anything with a driver.
 *
 * The atomicity guarantee comes from the backend rather than from a rename here: an S3 `PUT` to
 * an existing key is atomic, and a reader gets either the old object or the new one. Object
 * stores have no rename worth the name, so attempting the local trick would be slower and no
 * safer.
 */
class FsStore implements StoreInterface
{
    public function __construct(
        private readonly FsInterface $fs,
        private readonly string $prefix = '',
    ) {
    }

    public function write(string $path, string $contents): void
    {
        $this->fs->write($this->path($path), $contents);
    }

    public function exists(string $path): bool
    {
        return $this->fs->fileExists($this->path($path));
    }

    public function read(string $path): ?string
    {
        try {
            return $this->fs->read($this->path($path));
        } catch (Throwable) {
            return null;
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->fs->deleteFile($this->path($path));
        } catch (Throwable) {
            // Pruning is best-effort by design: two publishes can race to remove the same
            // superseded version, and losing that race is not a failure worth propagating.
        }
    }

    public function url(string $path): ?string
    {
        $root = $this->fs->getRootUrl();

        if ($root === null) {
            return null;
        }

        return rtrim($root, '/') . '/' . ltrim($this->path($path), '/');
    }

    public function describe(): string
    {
        $name = $this->fs->name ?? 'filesystem';

        return $this->prefix !== '' ? "{$name}:{$this->prefix}" : $name;
    }

    private function path(string $path): string
    {
        $prefix = trim($this->prefix, '/');

        return $prefix !== '' ? $prefix . '/' . ltrim($path, '/') : ltrim($path, '/');
    }
}
