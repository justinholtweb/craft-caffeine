<?php

namespace justinholtweb\caffeine\services;

use Craft;
use craft\base\Component;
use craft\helpers\DateTimeHelper;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\models\Settings;
use justinholtweb\caffeine\publish\FsStore;
use justinholtweb\caffeine\publish\LocalStore;
use justinholtweb\caffeine\publish\StoreInterface;
use justinholtweb\caffeine\search\Artifact;
use justinholtweb\caffeine\search\ArtifactEncoder;
use justinholtweb\caffeine\Plugin;
use RuntimeException;

/**
 * Writes a compiled artifact out to where the front end reads it.
 *
 * Three properties, and every decision here follows from one of them.
 *
 * **The payload is immutable and content-addressed.** Its filename is a hash of its own bytes,
 * so a given URL's contents can never change. It can be served with a one-year `immutable`
 * cache header, and a browser that has it never asks again.
 *
 * **The pointer is stable and mutable.** Cached HTML embeds the URL of `current.json` and
 * nothing else. That is what makes Caffeine work behind a full-page cache: the HTML can be
 * months old and still find today's index, because the thing it names never moves. `current.json`
 * is the one file that must be served uncached.
 *
 * **The pointer is written last.** Shards go down first, then the pointer that names them, so a
 * publish interrupted halfway leaves unreferenced shard files — which `prune()` collects — and
 * never a pointer to a file that does not exist. The reverse order would take the index down.
 */
class Publisher extends Component
{
    /** The stable pointer. The only mutable file Caffeine publishes, and the only uncached one. */
    public const POINTER = 'current.json';

    /** Hex characters of the content hash used in filenames. 64 bits; collisions are not a risk. */
    private const HASH_LENGTH = 16;

    private ?StoreInterface $store = null;

    /**
     * Encodes and hashes an artifact without writing anything.
     *
     * Split from `commit()` so the caller can compare the checksum against what is already
     * published and decide there is nothing to do — before a single byte is written, and before
     * a version number is spent. A rebuild that changed nothing should leave the store exactly
     * as it found it, including the pointer's timestamp.
     *
     * @return array{documents: array<string, array{name: string, contents: string, checksum: string, bytes: int}>, checksum: string, bytes: int, nbRecords: int}
     */
    public function prepare(IndexDefinition $index, Artifact $artifact): array
    {
        $encoded = (new ArtifactEncoder())->encode($artifact);

        // Sharding is a publishing decision, not a compilation one: the encoder always produces
        // both documents and this chooses whether they travel separately. Merged, the payload
        // rides along in one request; split, a facet-count request never fetches it at all.
        $documents = $index->shardPayload
            ? ['index' => $encoded['index'], 'payload' => $encoded['payload']]
            : ['index' => array_merge($encoded['index'], $encoded['payload'])];

        $prepared = [];
        $bytes = 0;

        foreach ($documents as $kind => $document) {
            $contents = $this->serialize($document);
            $checksum = substr(hash('sha256', $contents), 0, self::HASH_LENGTH);

            $prepared[$kind] = [
                'name' => "{$checksum}.{$kind}.json",
                'contents' => $contents,
                'checksum' => $checksum,
                'bytes' => strlen($contents),
            ];

            $bytes += strlen($contents);
        }

        return [
            'documents' => $prepared,
            // The identity of the whole artifact, not of either shard: two builds match only
            // when every shard matches. This is what the ledger compares to decide a rebuild
            // produced nothing new.
            'checksum' => hash('sha256', implode('|', array_map(fn(array $d) => $d['checksum'], $prepared))),
            'bytes' => $bytes,
            'nbRecords' => $artifact->recordCount(),
        ];
    }

    /**
     * Writes a prepared artifact and swings the pointer at it.
     *
     * @param array{documents: array<string, array{name: string, contents: string, checksum: string, bytes: int}>, checksum: string, bytes: int, nbRecords: int} $prepared
     * @return array{checksum: string, version: int, files: list<string>, bytes: int, manifest: array<string, mixed>, reused: bool}
     */
    public function commit(IndexDefinition $index, array $prepared, int $version): array
    {
        $settings = $this->settings();
        $store = $this->store();

        $shards = [];
        $files = [];
        $written = 0;

        foreach ($prepared['documents'] as $kind => $document) {
            $path = $this->path($index, $document['name']);

            // Content-addressed, so a shard whose bytes have not changed is already sitting at
            // exactly this filename. Two indexes that differ only in their payload republish
            // only the payload.
            if (!$store->exists($path)) {
                $store->write($path, $document['contents']);
                $written++;
            }

            $files[] = $path;

            foreach ($this->compress($document['contents'], $settings) as $extension => $compressed) {
                $sidecar = "{$path}.{$extension}";

                if (!$store->exists($sidecar)) {
                    $store->write($sidecar, $compressed);
                }

                $files[] = $sidecar;
            }

            $shards[$kind] = [
                'file' => $document['name'],
                'bytes' => $document['bytes'],
                'checksum' => $document['checksum'],
                'url' => $store->url($path),
            ];
        }

        $manifest = [
            'format' => ArtifactEncoder::FORMAT,
            'index' => $index->handle,
            'version' => $version,
            'generatedAt' => DateTimeHelper::toIso8601(new \DateTime()),
            'nbRecords' => $prepared['nbRecords'],
            'checksum' => $prepared['checksum'],
            'sharded' => $index->shardPayload,
            'transport' => $index->transport,
            'hitsPerPage' => $index->hitsPerPage,
            'shards' => $shards,
        ];

        // Last, and only now. Every shard it names is on disk, so a publish interrupted before
        // this point leaves unreferenced files that `prune()` collects — never a live pointer to
        // a file that was never written.
        $pointer = $this->path($index, self::POINTER);
        $store->write($pointer, $this->serialize($manifest));

        // Recorded against every version, which is what makes the ledger a complete inventory of
        // an index's published files — so tearing an index down needs nothing but its uid, long
        // after its definition has left project config. Pruning never removes it while any
        // version is retained, because every retained version lists it too.
        $files[] = $pointer;

        Craft::info(
            sprintf(
                'Published “%s” v%d (%d records, %s) to %s%s',
                $index->handle,
                $version,
                $prepared['nbRecords'],
                $this->formatBytes($prepared['bytes']),
                $store->describe(),
                $written === 0 ? ' — shards reused' : '',
            ),
            Plugin::LOG_CATEGORY,
        );

        return [
            'checksum' => $prepared['checksum'],
            'version' => $version,
            'files' => $files,
            'bytes' => $prepared['bytes'],
            'manifest' => $manifest,
            // Every shard was already on disk. The content matched something published before,
            // which happens when an index is reverted to an earlier state.
            'reused' => $written === 0,
        ];
    }

    /**
     * Removes published files.
     *
     * @param list<string> $files Paths exactly as recorded when they were written.
     */
    public function prune(array $files): void
    {
        $store = $this->store();

        foreach ($files as $file) {
            $store->delete($file);
        }
    }

    /**
     * Reads back the published pointer, or null when the index has never been published.
     *
     * @return array<string, mixed>|null
     */
    public function pointer(IndexDefinition $index): ?array
    {
        $contents = $this->store()->read($this->path($index, self::POINTER));

        if ($contents === null) {
            return null;
        }

        $manifest = json_decode($contents, true);

        return is_array($manifest) ? $manifest : null;
    }

    public function pointerUrl(IndexDefinition $index): ?string
    {
        return $this->store()->url($this->path($index, self::POINTER));
    }

    public function store(): StoreInterface
    {
        if ($this->store !== null) {
            return $this->store;
        }

        $settings = $this->settings();

        if ($settings->filesystemHandle === '') {
            return $this->store = LocalStore::forPath($settings->publishPath);
        }

        $fs = Craft::$app->getFs()->getFilesystemByHandle($settings->filesystemHandle);

        if ($fs === null) {
            throw new RuntimeException(sprintf(
                'Caffeine is configured to publish to the “%s” filesystem, which does not exist.',
                $settings->filesystemHandle,
            ));
        }

        return $this->store = new FsStore($fs, $settings->publishPath);
    }

    /** Lets tests and the CP preview point publishing somewhere harmless. */
    public function setStore(?StoreInterface $store): void
    {
        $this->store = $store;
    }

    public function path(IndexDefinition $index, string $name): string
    {
        return "{$index->handle}/{$name}";
    }

    /**
     * @return array<string, string> extension => compressed bytes
     */
    private function compress(string $contents, Settings $settings): array
    {
        if (!$settings->precompress) {
            return [];
        }

        $out = ['gz' => gzencode($contents, 9)];

        // Brotli is a compiled extension most hosts do not have. Where it exists it is worth
        // roughly another 15% over gzip on JSON, and where it does not, nginx simply never finds
        // a `.br` to serve and falls back to the `.gz` — so its absence costs nothing.
        if (function_exists('brotli_compress')) {
            $out['br'] = brotli_compress($contents, 11);
        }

        return array_filter($out, fn($value) => is_string($value) && $value !== '');
    }

    /**
     * `JSON_PRESERVE_ZERO_FRACTION` is load-bearing, not tidiness.
     *
     * Without it PHP encodes `float(1775403180.0)` as `1775403180`, which decodes back as an
     * *int* — so a numeric sortable value silently changes type on its way through the store, and
     * a published artifact stops matching the one it was compiled from. JavaScript cannot tell
     * the two apart, so the damage is invisible in the browser and shows up only where PHP reads
     * its own output back.
     */
    private function serialize(array $document): string
    {
        return json_encode(
            $document,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return sprintf('%.2f MB', $bytes / 1024 / 1024);
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
