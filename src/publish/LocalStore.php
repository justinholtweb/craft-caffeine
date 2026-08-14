<?php

namespace justinholtweb\caffeine\publish;

use Craft;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;
use RuntimeException;

/**
 * Publishes into the local web root, which is where most sites want their artifacts: served as
 * static files by nginx, never touching PHP.
 *
 * Writes are atomic through the usual temp-file-and-rename: `rename()` within a filesystem is
 * atomic on POSIX, so a request reading `current.json` mid-publish gets the old file intact
 * rather than a truncated one.
 */
class LocalStore implements StoreInterface
{
    public function __construct(
        private readonly string $root,
        private readonly string $baseUrl,
    ) {
    }

    public static function forPath(string $publishPath): self
    {
        $webroot = Craft::getAlias('@webroot');

        if (!is_string($webroot)) {
            throw new RuntimeException('Caffeine cannot publish locally: the @webroot alias is not set.');
        }

        $publishPath = trim($publishPath, '/');

        return new self(
            root: rtrim($webroot, '/') . ($publishPath !== '' ? '/' . $publishPath : ''),
            baseUrl: UrlHelper::siteUrl($publishPath),
        );
    }

    public function write(string $path, string $contents): void
    {
        $full = $this->path($path);

        FileHelper::createDirectory(dirname($full));

        // Same directory as the target, so the rename stays within one filesystem — across a
        // mount boundary `rename()` degrades to copy-then-delete and stops being atomic.
        $temporary = $full . '.tmp.' . bin2hex(random_bytes(6));

        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Caffeine could not write {$temporary}.");
        }

        if (!@rename($temporary, $full)) {
            @unlink($temporary);

            throw new RuntimeException("Caffeine could not publish {$full}.");
        }
    }

    public function exists(string $path): bool
    {
        return is_file($this->path($path));
    }

    public function read(string $path): ?string
    {
        $contents = @file_get_contents($this->path($path));

        return $contents === false ? null : $contents;
    }

    public function delete(string $path): void
    {
        $full = $this->path($path);

        @unlink($full);

        // An index's files all live in a directory named after it, and deleting the index leaves
        // that directory behind — empty, and there for good, since nothing else will ever look at
        // it again. A site that has created and dropped a few indexes over its life accumulates
        // them one per index. Guarded so it can only ever remove a directory *inside* the publish
        // root, and only when it is genuinely empty.
        $directory = dirname($full);
        $root = rtrim($this->root, '/');

        if ($directory === $root || !str_starts_with($directory, $root . '/')) {
            return;
        }

        if (is_dir($directory) && (@scandir($directory) ?: []) === ['.', '..']) {
            @rmdir($directory);
        }
    }

    public function url(string $path): ?string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    public function describe(): string
    {
        return $this->root;
    }

    private function path(string $path): string
    {
        return rtrim($this->root, '/') . '/' . ltrim($path, '/');
    }
}
