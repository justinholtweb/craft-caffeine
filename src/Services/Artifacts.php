<?php

namespace JustinHoltWeb\Caffeine\Services;

use Closure;
use CraftCms\Cms\Support\Json;
use CraftCms\Cms\Support\Query;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JustinHoltWeb\Caffeine\Database\Tables;
use JustinHoltWeb\Caffeine\Models\IndexDefinition;
use JustinHoltWeb\Caffeine\Plugin;
use JustinHoltWeb\Caffeine\Search\Artifact;
use JustinHoltWeb\Caffeine\Search\ArtifactDecoder;
use JustinHoltWeb\Caffeine\Search\ArtifactEncoder;
use JustinHoltWeb\Caffeine\Search\Compiler;
use RuntimeException;
use Throwable;

/**
 * Compiles, publishes and retires artifact versions.
 *
 * The ledger in `caffeine_artifacts` is not bookkeeping for its own sake. It answers the two
 * questions publishing cannot answer from the filesystem: which version is live right now, and
 * which files an older version still needs. Content-addressed filenames mean two versions
 * frequently share a shard — an edit that changes one product's title leaves the facet index
 * untouched — so "delete the old version's files" is only safe against a list of what everything
 * else still points at.
 */
class Artifacts
{
    public const STATUS_LIVE = 'live';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_FAILED = 'failed';

    /**
     * How long a publish waits for another one on the same index to finish.
     *
     * Long enough to sit out a large rebuild; short enough that a stuck lock fails a queue job
     * rather than holding the worker indefinitely.
     */
    private const LOCK_TIMEOUT = 120;

    /**
     * Expiry of the publish lock. Laravel locks outlive a crashed process (Yii's mutex was freed at
     * request end), so this is what frees an index whose publisher died. Sized well past the
     * longest publish.
     */
    private const LOCK_TTL = 600;

    /** A temporary file left by an interrupted local write, collectable once it is this old. */
    private const STALE_TEMPORARY = 3600;

    /** `<checksum>.<kind>.json`, with or without a compressed sidecar's extension. */
    private const CONTENT_ADDRESSED = '/^[0-9a-f]{16}\.[a-z]+\.json(\.(gz|br))?$/';

    /**
     * Compiles, publishes if anything changed, and retires what it replaced.
     *
     * @return array{published: bool, reason: string, version: int, checksum: string, records: int, bytes: int, buildMs: int, pruned: int, url: string|null}
     */
    public function publish(IndexDefinition $index): array
    {
        return $this->locked($index->uid, self::LOCK_TIMEOUT, fn () => $this->publishLocked($index));
    }

    /**
     * @return array{published: bool, reason: string, version: int, checksum: string, records: int, bytes: int, buildMs: int, pruned: int, url: string|null}
     */
    private function publishLocked(IndexDefinition $index): array
    {
        $startedAt = microtime(true);
        $publisher = app(Publisher::class);
        $version = $this->nextVersion($index->uid);

        $artifact = $this->compile($index, $version);
        $prepared = $publisher->prepare($index, $artifact);
        $live = $this->live($index->uid);

        // Nothing was written, no version was spent, and the pointer still carries its original
        // timestamp — a rebuild that changed nothing leaves the store exactly as it found it.
        // Downstream caches never see a reason to refetch.
        if ($live !== null && $live['checksum'] === $prepared['checksum']) {
            // The definition can change without changing a byte of the artifact — a label, a
            // facet no record has values for yet. Recorded anyway, or every later build would
            // see the old fingerprint and rebuild everything again for nothing.
            if (($live['definitionHash'] ?? null) !== $index->fingerprint()) {
                DB::table(Tables::ARTIFACTS)
                    ->where('id', $live['id'])
                    ->update(['definitionHash' => $index->fingerprint()]);
            }

            return [
                'published' => false,
                'reason' => 'unchanged',
                'version' => (int) $live['version'],
                'checksum' => $prepared['checksum'],
                'records' => $prepared['nbRecords'],
                'bytes' => $prepared['bytes'],
                'buildMs' => (int) round((microtime(true) - $startedAt) * 1000),
                'pruned' => 0,
                'url' => $publisher->pointerUrl($index),
            ];
        }

        try {
            $result = $publisher->commit($index, $prepared, $version);
        } catch (Throwable $e) {
            $this->record($index, $version, self::STATUS_FAILED, [
                'recordCount' => $prepared['nbRecords'],
                'checksum' => $prepared['checksum'],
                'error' => $e->getMessage(),
                'buildMs' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            throw $e;
        }

        $buildMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->supersedeLive($index->uid);

        $this->record($index, $version, self::STATUS_LIVE, [
            'recordCount' => $prepared['nbRecords'],
            'byteSize' => $prepared['bytes'],
            'checksum' => $prepared['checksum'],
            'files' => $result['files'],
            'buildMs' => $buildMs,
            'definitionHash' => $index->fingerprint(),
        ]);

        // Records for elements that have gone away have now fallen out of a published artifact,
        // so the rows that were only keeping the build honest can go.
        app(Records::class)->purgeDeleted($index->uid);

        return [
            'published' => true,
            'reason' => $result['reused'] ? 'republished' : 'built',
            'version' => $version,
            'checksum' => $prepared['checksum'],
            'records' => $prepared['nbRecords'],
            'bytes' => $prepared['bytes'],
            'buildMs' => $buildMs,
            'pruned' => $this->pruneLocked($index),
            'url' => $publisher->pointerUrl($index),
        ];
    }

    /**
     * Compiles the stored records into an artifact, without publishing it.
     */
    public function compile(IndexDefinition $index, int $version = 1): Artifact
    {
        return (new Compiler)->compile(
            // Downgraded here rather than at every call site: this is the only place a build
            // starts, so it is the only place the edition can change what gets built.
            app(Indexes::class)->forEdition($index),
            app(Records::class)->stream($index->uid),
            $version,
        );
    }

    /**
     * Reads the published artifact back out of the store.
     *
     * The server side of the two-engine design: the same bytes the browser fetches, decoded by
     * the same rules, so a server-rendered first paint and the refinement that follows it are
     * answering from identical data.
     *
     * Not called `load()`, for historical reasons: Craft 5 services inherited Yii's `Model::load()`,
     * and the name stuck.
     */
    public function published(IndexDefinition $index): ?Artifact
    {
        $publisher = app(Publisher::class);
        $manifest = $publisher->pointer($index);

        if ($manifest === null) {
            return null;
        }

        // With the payload sharded out and a lookup beside it, the payload shard is never read
        // here unless a hit needs it: a page's payloads come from the records instead.
        $lazy = isset($manifest['shards']['payload']['file'], $manifest['lookup']['file']);
        $loader = $lazy ? $this->payloadLoader($index, $manifest) : null;

        $cache = app(ArtifactCache::class);
        $cached = $cache->get($index, $manifest, $loader);

        if ($cached !== null) {
            return $cached;
        }

        $documents = [];

        foreach ($lazy ? ['index', 'lookup'] : ['index', 'payload'] as $kind) {
            $file = $kind === 'lookup' ? $manifest['lookup']['file'] : ($manifest['shards'][$kind]['file'] ?? null);

            if ($file === null) {
                continue;
            }

            $document = $this->readDocument($index, (string) $file);

            if ($document === null) {
                return null;
            }

            $documents[$kind] = $document;
        }

        if (! isset($documents['index'])) {
            return null;
        }

        $artifact = (new ArtifactDecoder)->decode(
            $documents['index'],
            $documents['payload'] ?? null,
            (int) ($manifest['version'] ?? 0),
            $documents['lookup'] ?? null,
            $loader,
        );

        unset($documents);
        $cache->put($index, $manifest, $artifact);

        return $artifact;
    }

    /**
     * Loads a page's payloads from the records they were compiled from.
     *
     * A record's payload is used only when it still hashes to what was published; anything
     * rebuilt or removed since then is answered from the payload shard instead, decoded at
     * most once per request. So a hit is always the published one, and the shard is only read
     * in the window between a record changing and the next publish, and only by a page that
     * shows that record.
     *
     * @param  array<string, mixed>  $manifest
     * @return Closure(list<int>, Artifact): array<int, array<string, mixed>>
     */
    private function payloadLoader(IndexDefinition $index, array $manifest): Closure
    {
        /** @var list<array<string, mixed>>|null $shard */
        $shard = null;

        return function (array $ids, Artifact $artifact) use ($index, $manifest, &$shard): array {
            $wanted = [];

            foreach ($ids as $id) {
                $objectId = $artifact->objectIds[$id] ?? null;

                if ($objectId !== null) {
                    $wanted[(string) $objectId] = $id;
                }
            }

            $payloads = $this->recordPayloads($index, $artifact, $wanted);
            $missing = array_diff_key(array_flip($ids), $payloads);

            if ($missing === []) {
                return $payloads;
            }

            if ($shard === null) {
                $document = $this->readDocument($index, (string) $manifest['shards']['payload']['file']);
                $shard = array_values((array) ($document['payloads'] ?? []));

                if ($document === null) {
                    Log::channel('caffeine')->warning(
                        sprintf('Caffeine could not read the payload shard for “%s”; some hits are shown without their content.', $index->handle),
                    );
                }
            }

            foreach (array_keys($missing) as $id) {
                $payloads[$id] = $shard[$id] ?? [];
            }

            return $payloads;
        };
    }

    /**
     * Payloads from the records table, for the records whose content still matches what was
     * published.
     *
     * Looked up by element id, which the records table indexes, and matched on object id after:
     * a record's object id is `<elementId>-<siteId>` (see `Mapper::objectId()`), and anything
     * else simply falls through to the shard.
     *
     * @param  array<string, int>  $wanted  objectId → internal id
     * @return array<int, array<string, mixed>> internal id → payload
     */
    private function recordPayloads(IndexDefinition $index, Artifact $artifact, array $wanted): array
    {
        $elementIds = [];

        foreach (array_keys($wanted) as $objectId) {
            if (preg_match('/^(\d+)-\d+$/', (string) $objectId, $m) === 1) {
                $elementIds[(int) $m[1]] = true;
            }
        }

        if ($elementIds === []) {
            return [];
        }

        $rows = DB::table(Tables::RECORDS)
            ->select(['objectId', 'content'])
            ->where('indexUid', $index->uid)
            ->whereIntegerInRaw('elementId', array_keys($elementIds))
            ->get();

        $payloads = [];

        foreach ($rows as $row) {
            $id = $wanted[(string) $row->objectId] ?? null;

            if ($id === null || $row->content === null) {
                continue;
            }

            // Decoded exactly the way `Records::stream()` hands content to the compiler, so an
            // unchanged record produces the very array that was published, and the same hash.
            $content = Json::decodeIfJson($row->content) ?: [];
            $payload = (array) ($content['payload'] ?? []);

            if (ArtifactEncoder::payloadHash($payload) === $artifact->payloadHash($id)) {
                $payloads[$id] = $payload;
            }
        }

        return $payloads;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readDocument(IndexDefinition $index, string $file): ?array
    {
        $publisher = app(Publisher::class);
        $contents = $publisher->store()->read($publisher->path($index, $file));

        if ($contents === null) {
            return null;
        }

        try {
            $document = Json::decode($contents);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return is_array($document) ? $document : null;
    }

    /**
     * Deletes versions beyond `keepVersions`, and the files only they referenced.
     *
     * @return int Versions retired.
     */
    public function prune(IndexDefinition $index): int
    {
        return $this->locked($index->uid, self::LOCK_TIMEOUT, fn () => $this->pruneLocked($index));
    }

    private function pruneLocked(IndexDefinition $index): int
    {
        $keep = max(1, Plugin::getInstance()->getSettings()->keepVersions);

        $rows = $this->rows(DB::table(Tables::ARTIFACTS)
            ->select(['id', 'version', 'status', 'files'])
            ->where('indexUid', $index->uid)
            ->orderByDesc('version'));

        $retained = [];
        $doomed = [];

        foreach ($rows as $i => $row) {
            // The live version is never a candidate however deep it sits, and the newest
            // `keepVersions` rows are held back because a visitor who loaded the page a moment
            // before the last rebuild is still fetching one of them.
            if ($i < $keep || $row['status'] === self::STATUS_LIVE) {
                $retained[] = $row;

                continue;
            }

            $doomed[] = $row;
        }

        if ($doomed === []) {
            return 0;
        }

        $keepFiles = [];

        foreach ($retained as $row) {
            foreach ((array) Json::decodeIfJson($row['files'] ?? '[]') as $file) {
                $keepFiles[$file] = true;
            }
        }

        $delete = [];

        foreach ($doomed as $row) {
            foreach ((array) Json::decodeIfJson($row['files'] ?? '[]') as $file) {
                // Shared with something still live or still retained. Content-addressed names
                // make this the common case rather than the exception: a rebuild that only
                // touched payload leaves the whole facet index sitting at its original path.
                if (isset($keepFiles[$file])) {
                    continue;
                }

                $delete[$file] = true;
            }
        }

        app(Publisher::class)->prune(array_keys($delete));

        DB::table(Tables::ARTIFACTS)
            ->whereIntegerInRaw('id', array_column($doomed, 'id'))
            ->delete();

        return count($doomed);
    }

    /**
     * The live version's ledger row, or null when the index has never published.
     *
     * @return array<string, mixed>|null
     */
    public function live(string $indexUid): ?array
    {
        $row = DB::table(Tables::ARTIFACTS)
            ->where(['indexUid' => $indexUid, 'status' => self::STATUS_LIVE])
            ->orderByDesc('version')
            ->first();

        return $row !== null ? (array) $row : null;
    }

    /**
     * Fingerprint of the definition the live artifact was published from, or null when nothing
     * is live or it was published before fingerprints were recorded.
     */
    public function definitionHash(string $indexUid): ?string
    {
        $hash = $this->live($indexUid)['definitionHash'] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function versions(string $indexUid, int $limit = 20): array
    {
        return $this->rows(DB::table(Tables::ARTIFACTS)
            ->select(['version', 'status', 'recordCount', 'byteSize', 'checksum', 'buildMs', 'error', 'dateCreated'])
            ->where('indexUid', $indexUid)
            ->orderByDesc('version')
            ->limit($limit));
    }

    /**
     * Everything the CP and the console need to describe an index's health in one call.
     *
     * @return array{records: int, dirty: int, version: int|null, status: string|null, checksum: string|null, bytes: int, publishedAt: string|null, buildMs: int|null, url: string|null, stale: bool}
     */
    public function status(IndexDefinition $index): array
    {
        $records = app(Records::class);
        $live = $this->live($index->uid);
        $dirty = $records->countFor($index->uid, true);

        return [
            'records' => $records->countFor($index->uid),
            'dirty' => $dirty,
            'version' => $live !== null ? (int) $live['version'] : null,
            'status' => $live !== null ? (string) $live['status'] : null,
            'checksum' => $live !== null ? (string) $live['checksum'] : null,
            'bytes' => $live !== null ? (int) $live['byteSize'] : 0,
            'publishedAt' => $live !== null ? (string) $live['dateCreated'] : null,
            'buildMs' => $live !== null ? (int) $live['buildMs'] : null,
            'url' => app(Publisher::class)->pointerUrl($index),
            // Records have moved since the live artifact was cut, so what visitors are being
            // served is behind the CMS.
            'stale' => $live === null || $dirty > 0,
        ];
    }

    /**
     * Retires the live version without deleting anything, so the next publish cannot decide the
     * artifact is unchanged.
     *
     * The escape hatch for the one case the checksum comparison gets wrong: the ledger says an
     * identical artifact is already published, but the files are not actually there — a wiped
     * web root, a fresh environment restored from a database dump, a filesystem handle repointed
     * somewhere new. Nothing here inspects the store, so nothing here can notice.
     */
    public function forget(IndexDefinition $index): void
    {
        $this->supersedeLive($index->uid);
    }

    private function supersedeLive(string $indexUid): void
    {
        DB::table(Tables::ARTIFACTS)
            ->where(['indexUid' => $indexUid, 'status' => self::STATUS_LIVE])
            ->update([
                'status' => self::STATUS_SUPERSEDED,
                'dateUpdated' => Query::prepareDateForDb(now()),
            ]);
    }

    public function nextVersion(string $indexUid): int
    {
        $max = DB::table(Tables::ARTIFACTS)
            ->where('indexUid', $indexUid)
            ->max('version');

        return $max === null ? 1 : (int) $max + 1;
    }

    /**
     * Removes every published file and ledger row for an index.
     *
     * Takes a uid rather than a definition because the caller is usually the project-config
     * handler reacting to a deletion, by which point the definition is already gone. The ledger's
     * `files` column is a complete inventory — the pointer included — so nothing here needs to
     * know the index's handle to find what it published.
     */
    public function deleteForIndex(string $indexUid): void
    {
        $rows = DB::table(Tables::ARTIFACTS)
            ->where('indexUid', $indexUid)
            ->pluck('files');

        $files = [];

        foreach ($rows as $list) {
            foreach ((array) Json::decodeIfJson($list ?? '[]') as $file) {
                $files[$file] = true;
            }
        }

        app(Publisher::class)->prune(array_keys($files));

        DB::table(Tables::ARTIFACTS)->where('indexUid', $indexUid)->delete();
    }

    /**
     * Removes published files and ledger rows for indexes that no longer exist.
     *
     * The counterpart to `Records::deleteOrphans()`, and the more important half: orphaned rows
     * waste a little storage, but orphaned *artifacts* sit in the web root being served forever.
     *
     * @param  list<string>  $keepUids  Uids currently defined in project config.
     * @return int Indexes cleaned up.
     */
    public function deleteOrphans(array $keepUids): int
    {
        $query = DB::table(Tables::ARTIFACTS)->distinct();

        if ($keepUids !== []) {
            $query->whereNotIn('indexUid', $keepUids);
        }

        $orphans = $query->pluck('indexUid')->all();

        foreach ($orphans as $uid) {
            $this->deleteForIndex((string) $uid);
        }

        return count($orphans);
    }

    /**
     * Deletes files in an index's publish directory that nothing references.
     *
     * Pruning only ever deletes what a ledger row lists, so a file no row lists is invisible to
     * it and stays forever: shards from a publish that failed after writing them, or files left
     * behind when the database was restored to a point before they were published. Their names
     * are content-addressed, so a shard no retained row lists and the live pointer does not name
     * is unreachable by construction, and safe to remove.
     *
     * Takes the same lock as publishing, without waiting. A publish can adopt an existing shard
     * by name before its ledger row exists, and sweeping in that window would delete a file the
     * new pointer is about to name.
     *
     * @return list<string>|null Paths deleted, or null when a publish held the lock.
     */
    public function sweep(IndexDefinition $index): ?array
    {
        return $this->locked($index->uid, 0, function () use ($index): array {
            $publisher = app(Publisher::class);
            $store = $publisher->store();
            $keep = [$publisher->path($index, Publisher::POINTER) => true];

            $rows = DB::table(Tables::ARTIFACTS)
                ->where('indexUid', $index->uid)
                ->pluck('files');

            foreach ($rows as $files) {
                foreach ((array) Json::decodeIfJson($files ?? '[]') as $file) {
                    $keep[$file] = true;
                }
            }

            // Whatever the live pointer names is kept even if the ledger has lost track of it: a
            // restored database can be older than the pointer, and that pointer is being served.
            $pointer = $publisher->pointer($index);

            foreach ($pointer['shards'] ?? [] as $shard) {
                $path = $publisher->path($index, (string) ($shard['file'] ?? ''));
                $keep[$path] = $keep["{$path}.gz"] = $keep["{$path}.br"] = true;
            }

            if (isset($pointer['lookup']['file'])) {
                $keep[$publisher->path($index, (string) $pointer['lookup']['file'])] = true;
            }

            $deleted = [];

            foreach ($store->files($index->handle) as $path => $modified) {
                if (isset($keep[$path])) {
                    continue;
                }

                $name = basename($path);
                $stray = preg_match(self::CONTENT_ADDRESSED, $name) === 1
                    || (str_contains($name, '.tmp.') && $modified !== null && $modified < time() - self::STALE_TEMPORARY);

                if ($stray) {
                    $store->delete($path);
                    $deleted[] = $path;
                }
            }

            return $deleted;
        });
    }

    /**
     * Runs a callback holding the index's publish lock.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  mixed  $busy  Returned instead when `$timeout` is zero and the lock is taken.
     * @return T
     */
    private function locked(string $indexUid, int $timeout, callable $callback, mixed $busy = null): mixed
    {
        $lock = Cache::lock("caffeine:publish:{$indexUid}", self::LOCK_TTL);

        if ($timeout === 0) {
            if (! $lock->get()) {
                return $busy;
            }
        } else {
            try {
                $lock->block($timeout);
            } catch (LockTimeoutException) {
                throw new RuntimeException("Another Caffeine publish of this index has held the lock for over {$timeout}s.");
            }
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function record(IndexDefinition $index, int $version, string $status, array $values): void
    {
        $now = Query::prepareDateForDb(now());

        DB::table(Tables::ARTIFACTS)->insert(array_merge([
            'indexUid' => $index->uid,
            'version' => $version,
            'status' => $status,
            'recordCount' => 0,
            'byteSize' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => (string) Str::uuid(),
        ], array_map(
            fn ($value) => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : $value,
            $values,
        )));
    }

    /**
     * Rows as plain arrays, the shape every caller of the ledger was written against.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(Builder $query): array
    {
        return $query->get()->map(fn (object $row) => (array) $row)->values()->all();
    }
}
