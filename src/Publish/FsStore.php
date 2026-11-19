<?php

namespace JustinHoltWeb\Caffeine\Publish;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use RuntimeException;
use Throwable;

/**
 * Publishes to a Laravel filesystem disk — S3, a CDN-backed bucket, anything with a driver.
 *
 * Craft 6 has no filesystems of its own any more; volumes and everything else sit on disks from
 * `config/filesystems.php`, and so does this.
 *
 * The atomicity guarantee comes from the backend rather than from a rename here: an S3 `PUT` to
 * an existing key is atomic, and a reader gets either the old object or the new one. Object
 * stores have no rename worth the name, so attempting the local trick would be slower and no
 * safer.
 */
class FsStore implements StoreInterface
{
    /**
     * @param  Filesystem  $disk  The disk, from `Storage::disk()`.
     * @param  string  $name  The disk's name in `config/filesystems.php`, for messages.
     */
    public function __construct(
        private readonly Filesystem $disk,
        private readonly string $name = 'disk',
        private readonly string $prefix = '',
    ) {}

    public function write(string $path, string $contents): void
    {
        // Disks default to `'throw' => false`, so a failed write is a `false` rather than an
        // exception. Turned back into one: a publish that silently wrote nothing would swing the
        // pointer at a shard that is not there.
        if (! $this->disk->put($this->path($path), $contents)) {
            throw new RuntimeException(sprintf('Caffeine could not write %s to the “%s” disk.', $this->path($path), $this->name));
        }
    }

    public function exists(string $path): bool
    {
        try {
            return $this->disk->exists($this->path($path));
        } catch (Throwable) {
            return false;
        }
    }

    public function read(string $path): ?string
    {
        try {
            return $this->disk->get($this->path($path));
        } catch (Throwable) {
            return null;
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->disk->delete($this->path($path));
        } catch (Throwable) {
            // Pruning is best-effort by design: two publishes can race to remove the same
            // superseded version, and losing that race is not a failure worth propagating.
        }
    }

    public function files(string $directory): array
    {
        $prefix = trim($this->prefix, '/');
        $files = [];

        try {
            foreach ($this->disk->files($this->path($directory)) as $file) {
                // Back to a store path: the listing is relative to the disk root, so it carries
                // the publish prefix that `path()` added.
                $uri = ltrim($file, '/');

                try {
                    $modified = $this->disk->lastModified($file);
                } catch (Throwable) {
                    $modified = null;
                }

                if ($prefix !== '' && str_starts_with($uri, $prefix.'/')) {
                    $uri = substr($uri, strlen($prefix) + 1);
                }

                $files[$uri] = $modified;
            }
        } catch (Throwable) {
            // A directory that is not there lists as nothing, whatever the driver thinks of it.
        }

        return $files;
    }

    public function url(string $path): ?string
    {
        // Drivers without public URLs throw rather than returning null — and a disk with no `url`
        // configured is exactly the "not web-accessible" case the interface answers null for.
        try {
            /** @var FilesystemAdapter $disk */
            $disk = $this->disk;

            return $disk->url($this->path($path));
        } catch (Throwable) {
            return null;
        }
    }

    public function describe(): string
    {
        return $this->prefix !== '' ? "{$this->name}:{$this->prefix}" : $this->name;
    }

    private function path(string $path): string
    {
        $prefix = trim($this->prefix, '/');

        return $prefix !== '' ? $prefix.'/'.ltrim($path, '/') : ltrim($path, '/');
    }
}
