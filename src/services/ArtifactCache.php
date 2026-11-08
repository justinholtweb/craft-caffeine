<?php

namespace justinholtweb\caffeine\services;

use Closure;
use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\Plugin;
use justinholtweb\caffeine\search\Artifact;
use justinholtweb\caffeine\search\ArtifactEncoder;
use Throwable;

/**
 * Keeps decoded artifacts in OPcache, so a request reads one rather than decoding it.
 *
 * With the `htmx` transport every refinement is a request, and every request used to read the
 * published JSON and decode it again. Here the decoded artifact is written once as a PHP file
 * that returns its arrays. OPcache compiles that file into shared memory, and from then on an
 * `include` hands back arrays that live there: no read, no decode, and no memory charged to the
 * request, however large the artifact.
 *
 * APCu and Craft's cache were the other candidates, and neither helps: both hand back a copy, and
 * building that copy costs about what decoding does.
 *
 * Opt-in, because it only pays when OPcache can hold the file, and the file is big — about
 * 180 MB of OPcache memory for 37,000 products with 140 facets. A file that does not fit is
 * recompiled by every request that includes it, which is slower than decoding. So nothing is
 * read from here unless OPcache confirms it has the file cached; when it will not take it, that
 * is logged once and the artifact is decoded the ordinary way.
 *
 * When the payloads are loaded on demand (see `Artifacts::published()`), they are left out of the
 * file too, which takes the payload's share off the OPcache memory it needs.
 *
 * Files are named by what they were decoded from, so a publish makes a new file rather than
 * changing an old one, and OPcache never has to notice a change.
 */
class ArtifactCache extends Component
{
    /**
     * The decoded artifact, if OPcache already holds it.
     *
     * @param array<string, mixed> $manifest The live pointer the artifact was published under.
     * @param (Closure(list<int>, Artifact): array<int, array<string, mixed>>)|null $payloadLoader
     *        Reattached when the cached artifact was written without its payloads.
     */
    public function get(IndexDefinition $index, array $manifest, ?Closure $payloadLoader = null): ?Artifact
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $path = $this->path($index, $manifest);

        if (!@opcache_is_script_cached($path)) {
            return null;
        }

        try {
            $data = include $path;

            // Passed straight through: arrays OPcache holds are only copied if written to, and
            // nothing on the query path writes to an artifact.
            return new Artifact(
                index: $data['index'],
                version: (int)($manifest['version'] ?? 0),
                objectIds: $data['objectIds'],
                payloads: $data['payloads'],
                facets: $data['facets'],
                sortings: $data['sortings'],
                tokens: $data['tokens'],
                tokenIds: $data['tokenIds'],
                tokenWeights: $data['tokenWeights'],
                sortableValues: $data['sortableValues'],
                stopwords: $data['stopwords'],
                geo: $data['geo'],
                tokenOffsets: $data['tokenOffsets'],
                payloadHashes: $data['payloadHashes'] ?? '',
                // Without a loader, an artifact cached without its payloads would serve empty
                // hits, so it is not served at all.
                payloadLoader: ($data['lazy'] ?? false) ? ($payloadLoader ?? throw new \RuntimeException('it was cached without payloads')) : null,
            );
        } catch (Throwable $e) {
            Craft::warning("Caffeine could not read its cached artifact {$path}: {$e->getMessage()}", Plugin::LOG_CATEGORY);

            return null;
        }
    }

    /**
     * Writes a decoded artifact and has OPcache compile it.
     *
     * The compile is the expensive part — a couple of seconds for a large catalogue — and it
     * happens once per publish on each web server, in whichever request gets here first. Every
     * other request arriving meanwhile decodes as before rather than queueing behind it.
     *
     * @param array<string, mixed> $manifest
     */
    public function put(IndexDefinition $index, array $manifest, Artifact $artifact): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $path = $this->path($index, $manifest);

        // OPcache said no to this file recently. Asking on every request would cost each one a
        // compile; asking again after an hour notices an operator who has raised the limits.
        $failed = @filemtime("{$path}.failed");

        if ($failed !== false && $failed > time() - 3600) {
            return;
        }

        $mutex = Craft::$app->getMutex();
        $lock = 'caffeine:opcache:' . basename($path);

        if (!$mutex->acquire($lock)) {
            return;
        }

        try {
            if (!file_exists($path)) {
                $this->write($path, $artifact);
                $this->deleteSiblings($path);
            }

            if (@opcache_compile_file($path) && @opcache_is_script_cached($path)) {
                @unlink("{$path}.failed");
            } else {
                @touch("{$path}.failed");

                Craft::warning(sprintf(
                    'Caffeine’s artifact cache for “%s” is %s, and OPcache would not hold it, so the artifact is decoded on every request instead. ' .
                    'Raise opcache.memory_consumption and opcache.interned_strings_buffer, check opcache.max_file_size, or turn the artifact cache off.',
                    $index->handle,
                    $this->formatBytes((int)@filesize($path)),
                ), Plugin::LOG_CATEGORY);
            }
        } catch (Throwable $e) {
            Craft::warning("Caffeine could not cache the artifact for “{$index->handle}”: {$e->getMessage()}", Plugin::LOG_CATEGORY);
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * Deletes the cache files of indexes that no longer exist.
     *
     * @param list<string> $knownUids
     * @return int Directories removed.
     */
    public function deleteOrphans(array $knownUids): int
    {
        $root = $this->root();

        if (!is_dir($root)) {
            return 0;
        }

        $removed = 0;

        foreach (FileHelper::findDirectories($root, ['recursive' => false]) as $directory) {
            if (!in_array(basename($directory), $knownUids, true)) {
                $this->invalidateAll($directory);
                FileHelper::removeDirectory($directory);
                $removed++;
            }
        }

        return $removed;
    }

    public function isEnabled(): bool
    {
        if (!Plugin::getInstance()->getSettings()->cacheArtifacts) {
            return false;
        }

        if (!function_exists('opcache_compile_file') || !function_exists('opcache_is_script_cached')) {
            return false;
        }

        // Off in the console almost everywhere, and a console command decodes an artifact once
        // anyway. Compiling it there would only cost time.
        return filter_var(ini_get(PHP_SAPI === 'cli' ? 'opcache.enable_cli' : 'opcache.enable'), FILTER_VALIDATE_BOOL);
    }

    /**
     * Named by the shards it was decoded from, plus everything that could change the decoded
     * shape without changing the shards: the wire format, and this plugin's version, since an
     * upgrade can change what `Artifact` holds.
     *
     * @param array<string, mixed> $manifest
     */
    public function path(IndexDefinition $index, array $manifest): string
    {
        $files = array_map(fn(array $shard) => (string)($shard['file'] ?? ''), (array)($manifest['shards'] ?? []));
        // Whether the payloads are in the file or loaded on demand depends on this.
        $files['lookup'] = (string)($manifest['lookup']['file'] ?? '');

        $key = substr(hash('sha256', implode('|', [
            ArtifactEncoder::FORMAT,
            Plugin::getInstance()->getVersion(),
            json_encode($files),
        ])), 0, 16);

        return $this->root() . DIRECTORY_SEPARATOR . $index->uid . DIRECTORY_SEPARATOR . $key . '.php';
    }

    private function root(): string
    {
        return Craft::$app->getPath()->getRuntimePath() . DIRECTORY_SEPARATOR . 'caffeine' . DIRECTORY_SEPARATOR . 'artifacts';
    }

    /**
     * One top-level key at a time, so the request never holds the whole file as one string
     * next to the artifact it came from. Written beside the target and renamed into place, so
     * no request can include half a file.
     */
    private function write(string $path, Artifact $artifact): void
    {
        FileHelper::createDirectory(dirname($path));

        $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
        $handle = fopen($temporary, 'wb');

        if ($handle === false) {
            throw new \RuntimeException("Could not write {$temporary}.");
        }

        try {
            fwrite($handle, "<?php\n\n// Written by Caffeine. Safe to delete; it is rebuilt on demand.\n\nreturn [\n");

            foreach ([
                'index' => $artifact->index,
                'objectIds' => $artifact->objectIds,
                'payloads' => $artifact->payloads,
                'facets' => $artifact->facets,
                'sortings' => $artifact->sortings,
                'tokens' => $artifact->tokens,
                'tokenIds' => $artifact->tokenIds,
                'tokenWeights' => $artifact->tokenWeights,
                'tokenOffsets' => $artifact->tokenOffsets,
                'sortableValues' => $artifact->sortableValues,
                'stopwords' => $artifact->stopwords,
                'geo' => $artifact->geo,
                'payloadHashes' => $artifact->payloadHashes,
                'lazy' => !$artifact->hasPayloads(),
            ] as $key => $value) {
                fwrite($handle, var_export($key, true) . ' => ' . var_export($value, true) . ",\n");
            }

            fwrite($handle, "];\n");
        } finally {
            fclose($handle);
        }

        // Backdated, because OPcache refuses to cache a file modified within the last
        // `opcache.file_update_protection` seconds — two, by default — in case it is still being
        // written. This one is complete before it is renamed into place.
        touch($temporary, time() - 60);

        if (!rename($temporary, $path)) {
            @unlink($temporary);

            throw new \RuntimeException("Could not move {$temporary} into place.");
        }
    }

    /**
     * Earlier versions of this index's cache. Nothing reads them once the pointer has moved on,
     * and telling OPcache lets it reclaim their memory when it next restarts.
     */
    private function deleteSiblings(string $path): void
    {
        foreach (glob(dirname($path) . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if ($file === $path || str_contains($file, '.tmp.')) {
                continue;
            }

            if (str_ends_with($file, '.php')) {
                @opcache_invalidate($file, true);
            }

            @unlink($file);
        }
    }

    private function invalidateAll(string $directory): void
    {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            @opcache_invalidate($file, true);
        }
    }

    private function formatBytes(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576) . ' MB' : round($bytes / 1024) . ' KB';
    }
}
