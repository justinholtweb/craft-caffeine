<?php

namespace justinholtweb\caffeine\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use justinholtweb\caffeine\migrations\Install;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\Plugin;
use justinholtweb\caffeine\search\Artifact;
use justinholtweb\caffeine\search\ArtifactDecoder;
use justinholtweb\caffeine\search\Compiler;
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
class Artifacts extends Component
{
    public const STATUS_LIVE = 'live';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_FAILED = 'failed';

    /**
     * Compiles, publishes if anything changed, and retires what it replaced.
     *
     * @return array{published: bool, reason: string, version: int, checksum: string, records: int, bytes: int, buildMs: int, pruned: int, url: string|null}
     */
    public function publish(IndexDefinition $index): array
    {
        $startedAt = microtime(true);
        $publisher = Plugin::getInstance()->publisher;
        $version = $this->nextVersion($index->uid);

        $artifact = $this->compile($index, $version);
        $prepared = $publisher->prepare($index, $artifact);
        $live = $this->live($index->uid);

        // Nothing was written, no version was spent, and the pointer still carries its original
        // timestamp — a rebuild that changed nothing leaves the store exactly as it found it.
        // Downstream caches never see a reason to refetch.
        if ($live !== null && $live['checksum'] === $prepared['checksum']) {
            return [
                'published' => false,
                'reason' => 'unchanged',
                'version' => (int)$live['version'],
                'checksum' => $prepared['checksum'],
                'records' => $prepared['nbRecords'],
                'bytes' => $prepared['bytes'],
                'buildMs' => (int)round((microtime(true) - $startedAt) * 1000),
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
                'buildMs' => (int)round((microtime(true) - $startedAt) * 1000),
            ]);

            throw $e;
        }

        $buildMs = (int)round((microtime(true) - $startedAt) * 1000);

        Craft::$app->getDb()->createCommand()
            ->update(Install::TABLE_ARTIFACTS, [
                'status' => self::STATUS_SUPERSEDED,
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            ], ['indexUid' => $index->uid, 'status' => self::STATUS_LIVE])
            ->execute();

        $this->record($index, $version, self::STATUS_LIVE, [
            'recordCount' => $prepared['nbRecords'],
            'byteSize' => $prepared['bytes'],
            'checksum' => $prepared['checksum'],
            'files' => $result['files'],
            'buildMs' => $buildMs,
        ]);

        // Records for elements that have gone away have now fallen out of a published artifact,
        // so the rows that were only keeping the build honest can go.
        Plugin::getInstance()->records->purgeDeleted($index->uid);

        return [
            'published' => true,
            'reason' => $result['reused'] ? 'republished' : 'built',
            'version' => $version,
            'checksum' => $prepared['checksum'],
            'records' => $prepared['nbRecords'],
            'bytes' => $prepared['bytes'],
            'buildMs' => $buildMs,
            'pruned' => $this->prune($index),
            'url' => $publisher->pointerUrl($index),
        ];
    }

    /**
     * Compiles the stored records into an artifact, without publishing it.
     */
    public function compile(IndexDefinition $index, int $version = 1): Artifact
    {
        return (new Compiler())->compile(
            // Downgraded here rather than at every call site: this is the only place a build
            // starts, so it is the only place the edition can change what gets built.
            Plugin::getInstance()->indexes->forEdition($index),
            Plugin::getInstance()->records->stream($index->uid),
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
     * Not called `load()`: `craft\base\Component` inherits `Model::load()`, and overriding it
     * with a different signature is a fatal compile error rather than a warning.
     */
    public function published(IndexDefinition $index): ?Artifact
    {
        $publisher = Plugin::getInstance()->publisher;
        $manifest = $publisher->pointer($index);

        if ($manifest === null) {
            return null;
        }

        $store = $publisher->store();
        $documents = [];

        foreach (['index', 'payload'] as $kind) {
            $file = $manifest['shards'][$kind]['file'] ?? null;

            if ($file === null) {
                continue;
            }

            $contents = $store->read($publisher->path($index, $file));

            if ($contents === null) {
                return null;
            }

            $documents[$kind] = Json::decode($contents);
        }

        if (!isset($documents['index'])) {
            return null;
        }

        return (new ArtifactDecoder())->decode(
            $documents['index'],
            $documents['payload'] ?? null,
            (int)($manifest['version'] ?? 0),
        );
    }

    /**
     * Deletes versions beyond `keepVersions`, and the files only they referenced.
     *
     * @return int Versions retired.
     */
    public function prune(IndexDefinition $index): int
    {
        $keep = max(1, Plugin::getInstance()->getSettings()->keepVersions);

        $rows = (new Query())
            ->select(['id', 'version', 'status', 'files'])
            ->from(Install::TABLE_ARTIFACTS)
            ->where(['indexUid' => $index->uid])
            ->orderBy(['version' => SORT_DESC])
            ->all();

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
            foreach ((array)Json::decodeIfJson($row['files'] ?? '[]') as $file) {
                $keepFiles[$file] = true;
            }
        }

        $delete = [];

        foreach ($doomed as $row) {
            foreach ((array)Json::decodeIfJson($row['files'] ?? '[]') as $file) {
                // Shared with something still live or still retained. Content-addressed names
                // make this the common case rather than the exception: a rebuild that only
                // touched payload leaves the whole facet index sitting at its original path.
                if (isset($keepFiles[$file])) {
                    continue;
                }

                $delete[$file] = true;
            }
        }

        Plugin::getInstance()->publisher->prune(array_keys($delete));

        Craft::$app->getDb()->createCommand()
            ->delete(Install::TABLE_ARTIFACTS, ['id' => array_column($doomed, 'id')])
            ->execute();

        return count($doomed);
    }

    /**
     * The live version's ledger row, or null when the index has never published.
     *
     * @return array<string, mixed>|null
     */
    public function live(string $indexUid): ?array
    {
        $row = (new Query())
            ->from(Install::TABLE_ARTIFACTS)
            ->where(['indexUid' => $indexUid, 'status' => self::STATUS_LIVE])
            ->orderBy(['version' => SORT_DESC])
            ->one();

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function versions(string $indexUid, int $limit = 20): array
    {
        return (new Query())
            ->select(['version', 'status', 'recordCount', 'byteSize', 'checksum', 'buildMs', 'error', 'dateCreated'])
            ->from(Install::TABLE_ARTIFACTS)
            ->where(['indexUid' => $indexUid])
            ->orderBy(['version' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * Everything the CP and the console need to describe an index's health in one call.
     *
     * @return array{records: int, dirty: int, version: int|null, status: string|null, checksum: string|null, bytes: int, publishedAt: string|null, buildMs: int|null, url: string|null, stale: bool}
     */
    public function status(IndexDefinition $index): array
    {
        $records = Plugin::getInstance()->records;
        $live = $this->live($index->uid);
        $dirty = $records->countFor($index->uid, true);

        return [
            'records' => $records->countFor($index->uid),
            'dirty' => $dirty,
            'version' => $live !== null ? (int)$live['version'] : null,
            'status' => $live !== null ? (string)$live['status'] : null,
            'checksum' => $live !== null ? (string)$live['checksum'] : null,
            'bytes' => $live !== null ? (int)$live['byteSize'] : 0,
            'publishedAt' => $live !== null ? (string)$live['dateCreated'] : null,
            'buildMs' => $live !== null ? (int)$live['buildMs'] : null,
            'url' => Plugin::getInstance()->publisher->pointerUrl($index),
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
        Craft::$app->getDb()->createCommand()
            ->update(Install::TABLE_ARTIFACTS, [
                'status' => self::STATUS_SUPERSEDED,
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            ], ['indexUid' => $index->uid, 'status' => self::STATUS_LIVE])
            ->execute();
    }

    public function nextVersion(string $indexUid): int
    {
        $max = (new Query())
            ->from(Install::TABLE_ARTIFACTS)
            ->where(['indexUid' => $indexUid])
            ->max('version');

        return $max === null ? 1 : (int)$max + 1;
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
        $rows = (new Query())
            ->select(['files'])
            ->from(Install::TABLE_ARTIFACTS)
            ->where(['indexUid' => $indexUid])
            ->all();

        $files = [];

        foreach ($rows as $row) {
            foreach ((array)Json::decodeIfJson($row['files'] ?? '[]') as $file) {
                $files[$file] = true;
            }
        }

        Plugin::getInstance()->publisher->prune(array_keys($files));

        Craft::$app->getDb()->createCommand()
            ->delete(Install::TABLE_ARTIFACTS, ['indexUid' => $indexUid])
            ->execute();
    }

    /**
     * Removes published files and ledger rows for indexes that no longer exist.
     *
     * The counterpart to `Records::deleteOrphans()`, and the more important half: orphaned rows
     * waste a little storage, but orphaned *artifacts* sit in the web root being served forever.
     *
     * @param list<string> $keepUids Uids currently defined in project config.
     * @return int Indexes cleaned up.
     */
    public function deleteOrphans(array $keepUids): int
    {
        $query = (new Query())
            ->select(['indexUid'])
            ->distinct()
            ->from(Install::TABLE_ARTIFACTS);

        if ($keepUids !== []) {
            $query->where(['not', ['indexUid' => $keepUids]]);
        }

        $orphans = $query->column();

        foreach ($orphans as $uid) {
            $this->deleteForIndex((string)$uid);
        }

        return count($orphans);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function record(IndexDefinition $index, int $version, string $status, array $values): void
    {
        $now = Db::prepareDateForDb(new \DateTime());

        Craft::$app->getDb()->createCommand()->insert(Install::TABLE_ARTIFACTS, array_merge([
            'indexUid' => $index->uid,
            'version' => $version,
            'status' => $status,
            'recordCount' => 0,
            'byteSize' => 0,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ], array_map(
            fn($value) => is_array($value) ? Json::encode($value) : $value,
            $values,
        )))->execute();
    }
}
