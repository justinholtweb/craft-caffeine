<?php

namespace JustinHoltWeb\Caffeine\Services;

use CraftCms\Cms\Support\Json;
use CraftCms\Cms\Support\Query;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JustinHoltWeb\Caffeine\Database\Tables;
use JustinHoltWeb\Caffeine\Models\IndexDefinition;
use JustinHoltWeb\Caffeine\Models\MappedRecord;

/**
 * The record store: mapped records, their dependency edges, and what is stale.
 *
 * Written with the query builder rather than Eloquent throughout. A build touches tens of
 * thousands of rows, and hydrating a model per row is most of the cost of doing so.
 *
 * Element-id lists go through `whereIntegerInRaw()`/`whereIntegerNotInRaw()`, which inline the
 * (integer-cast) ids rather than binding each one. Laravel uses native prepares on MySQL, where a
 * statement is capped at 65,535 placeholders — and the keep-list a full build prunes against is
 * every element id in the index.
 */
class Records
{
    /**
     * Rows per batch when writing. Big enough that the round trips stop mattering, small
     * enough to stay well under MySQL's default max_allowed_packet with fat payloads.
     */
    private const BATCH_SIZE = 500;

    /** The unique index every upsert targets — Postgres requires the conflict target to match one. */
    private const UNIQUE_KEY = ['indexUid', 'elementId', 'siteId'];

    /**
     * Writes a mapped record, replacing whatever was there, and refreshes its dependency edges.
     */
    public function save(IndexDefinition $index, MappedRecord $record): void
    {
        $now = $this->timestamp();

        // Encoded once: tokenising is the most expensive thing in a build, and the upsert
        // needs the same values in both its insert and update halves.
        $content = $this->encode($record->toArray());
        $tokens = $this->encode(app(Tokenizer::class)->tokenizeRecord($index, $record));

        // Laravel's `update` names columns whose new values come from the inserted row, so the
        // insert row carries exactly what an existing row should be updated to.
        DB::table(Tables::RECORDS)->upsert([[
            'indexUid' => $index->uid,
            'elementId' => $record->elementId,
            'siteId' => $record->siteId,
            'objectId' => $record->objectId,
            'content' => $content,
            'tokens' => $tokens,
            'dirty' => false,
            'deleted' => false,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => (string) Str::uuid(),
        ]], self::UNIQUE_KEY, ['objectId', 'content', 'tokens', 'dirty', 'deleted', 'dateUpdated']);

        $recordId = $this->recordId($index->uid, $record->elementId, $record->siteId);

        if ($recordId !== null) {
            $this->saveDependencies($recordId, $record->dependencies);
        }
    }

    /**
     * Writes many records in batches.
     *
     * @param  MappedRecord[]  $records
     */
    public function saveAll(IndexDefinition $index, array $records): void
    {
        foreach (array_chunk($records, self::BATCH_SIZE) as $chunk) {
            DB::transaction(function () use ($index, $chunk) {
                foreach ($chunk as $record) {
                    $this->save($index, $record);
                }
            });
        }
    }

    /**
     * Marks an element as needing a build, creating a placeholder row if it has none.
     *
     * The event handlers mark rows dirty and never build inline, which leaves a gap: an element
     * that has just been created has no row to mark, so nothing would ever notice it. A stub —
     * the keys, `dirty`, and no content — closes it. `stream()` skips rows with no content, so a
     * publish landing between the stub and the build cannot serve a blank hit.
     *
     * @param  list<int>  $siteIds
     */
    public function touchElement(string $indexUid, int $elementId, array $siteIds): void
    {
        $now = $this->timestamp();

        foreach ($siteIds as $siteId) {
            DB::table(Tables::RECORDS)->upsert([[
                'indexUid' => $indexUid,
                'elementId' => $elementId,
                'siteId' => $siteId,
                'objectId' => $elementId.'-'.$siteId,
                'content' => null,
                'tokens' => null,
                'dirty' => true,
                'deleted' => false,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => (string) Str::uuid(),
            ]], self::UNIQUE_KEY, [
                // Only the flags. An existing record keeps its content until the build replaces
                // it, so the artifact stays servable the whole time it is stale.
                'dirty',
                'deleted',
                'dateUpdated',
            ]);
        }
    }

    public function recordId(string $indexUid, int $elementId, int $siteId): ?int
    {
        $id = DB::table(Tables::RECORDS)
            ->where(['indexUid' => $indexUid, 'elementId' => $elementId, 'siteId' => $siteId])
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Replaces a record's dependency edges.
     *
     * Deleted and re-inserted wholesale rather than diffed: a record's dependencies change
     * whenever its content does, the sets are small, and a diff would need a read anyway.
     *
     * @param  list<int>  $elementIds
     */
    public function saveDependencies(int $recordId, array $elementIds): void
    {
        DB::table(Tables::DEPS)->where('recordId', $recordId)->delete();

        if ($elementIds === []) {
            return;
        }

        $now = $this->timestamp();
        $rows = [];

        foreach (array_unique($elementIds) as $elementId) {
            $rows[] = [
                'recordId' => $recordId,
                'elementId' => $elementId,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => (string) Str::uuid(),
            ];
        }

        foreach (array_chunk($rows, self::BATCH_SIZE) as $chunk) {
            DB::table(Tables::DEPS)->insert($chunk);
        }
    }

    /**
     * Marks every record that depends on any of these elements as needing a rebuild.
     *
     * This is the query that keeps denormalised values honest, and the reason `caffeine_deps`
     * carries an index on `elementId` alone.
     *
     * @param  list<int>  $elementIds
     * @return int Rows marked.
     */
    public function markDirtyByDependency(array $elementIds): int
    {
        if ($elementIds === []) {
            return 0;
        }

        $recordIds = DB::table(Tables::DEPS)
            ->distinct()
            ->whereIntegerInRaw('elementId', $elementIds)
            ->pluck('recordId')
            ->all();

        if ($recordIds === []) {
            return 0;
        }

        return DB::table(Tables::RECORDS)
            ->whereIntegerInRaw('id', $recordIds)
            ->update([
                'dirty' => true,
                'dateUpdated' => $this->timestamp(),
            ]);
    }

    /**
     * Which indexes hold a record depending on any of these elements.
     *
     * Marking dirty and knowing what to reschedule are two different questions, and answering
     * the second by index rather than by row is what keeps a save from queueing a job for every
     * index on the site.
     *
     * @param  list<int>  $elementIds
     * @return list<string>
     */
    public function indexUidsDependingOn(array $elementIds): array
    {
        if ($elementIds === []) {
            return [];
        }

        return DB::table(Tables::RECORDS.' as r')
            ->join(Tables::DEPS.' as d', 'd.recordId', '=', 'r.id')
            ->distinct()
            ->whereIntegerInRaw('d.elementId', $elementIds)
            ->pluck('r.indexUid')
            ->map(fn ($uid) => (string) $uid)
            ->all();
    }

    public function markIndexDirty(string $indexUid): int
    {
        return DB::table(Tables::RECORDS)
            ->where('indexUid', $indexUid)
            ->update([
                'dirty' => true,
                'dateUpdated' => $this->timestamp(),
            ]);
    }

    /**
     * Flags records as gone without removing them.
     *
     * Kept rather than deleted so the next publish knows the artifact changed and can rebuild
     * from the store alone. `purgeDeleted()` clears them once that has happened.
     *
     * @param  list<int>  $elementIds
     */
    public function markDeleted(string $indexUid, array $elementIds): int
    {
        if ($elementIds === []) {
            return 0;
        }

        return DB::table(Tables::RECORDS)
            ->where('indexUid', $indexUid)
            ->whereIntegerInRaw('elementId', $elementIds)
            ->update([
                'deleted' => true,
                'dirty' => true,
                'dateUpdated' => $this->timestamp(),
            ]);
    }

    public function purgeDeleted(string $indexUid): int
    {
        return DB::table(Tables::RECORDS)
            ->where(['indexUid' => $indexUid, 'deleted' => true])
            ->delete();
    }

    /**
     * Removes records belonging to indexes that no longer exist.
     *
     * `deleteForIndex()` handles the tidy path — an index deleted through the CP or through
     * project config. This is for the untidy one: an index whose uid *changed*. A restored
     * project config, a botched merge, a re-run seeding script, and the old rows are orphaned
     * with nothing left pointing at them. They cost storage, and they turn up in the dependency
     * map as indexes that cannot be scheduled because they are not there.
     *
     * @param  list<string>  $keepUids  Uids currently defined in project config.
     * @return int Rows removed.
     */
    public function deleteOrphans(array $keepUids): int
    {
        $query = DB::table(Tables::RECORDS);

        if ($keepUids === []) {
            $query->whereNotNull('indexUid');
        } else {
            $query->whereNotIn('indexUid', $keepUids);
        }

        return $query->delete();
    }

    public function deleteForIndex(string $indexUid): int
    {
        return DB::table(Tables::RECORDS)->where('indexUid', $indexUid)->delete();
    }

    /**
     * Removes records for elements the index no longer covers.
     *
     * The complement of `markDeleted`: an entry that was moved to a different section is not
     * deleted, it simply stopped qualifying, and nothing would ever notice without this.
     *
     * @param  list<int>  $keepElementIds
     */
    public function pruneMissing(string $indexUid, array $keepElementIds): int
    {
        $query = DB::table(Tables::RECORDS)->where('indexUid', $indexUid);

        if ($keepElementIds !== []) {
            // Laravel's docblock types this parameter wrongly; any list of integers is what it takes.
            $query->whereIntegerNotInRaw('elementId', $keepElementIds); // @phpstan-ignore argument.type
        }

        return $query->delete();
    }

    public function countFor(string $indexUid, bool $dirtyOnly = false): int
    {
        $query = DB::table(Tables::RECORDS)
            ->where(['indexUid' => $indexUid, 'deleted' => false]);

        if ($dirtyOnly) {
            $query->where('dirty', true);
        }

        return $query->count();
    }

    /**
     * Streams every live record for an index, decoded, in batches.
     *
     * A generator rather than an array: an artifact build reads the whole store, and a 100,000
     * record index would not fit in memory as decoded PHP arrays.
     *
     * @return Generator<array{elementId: int, siteId: int, objectId: string, content: array, tokens: array}>
     */
    public function stream(string $indexUid): Generator
    {
        $after = null;

        // Keyset-paginated on (elementId, siteId) rather than by offset: the unique index covers
        // it, so every page is an index seek however deep into a 100,000-record store it is.
        while (true) {
            $query = DB::table(Tables::RECORDS)
                ->select(['elementId', 'siteId', 'objectId', 'content', 'tokens'])
                ->where(['indexUid' => $indexUid, 'deleted' => false])
                // `content is not null` excludes the stubs `touchElement()` writes for elements
                // that have been marked but not yet built. Without it a publish between the two
                // would put an empty hit in the artifact.
                ->whereNotNull('content')
                // Ordered so a build is reproducible: the record at position 12 is the same
                // record on every run, which keeps postings lists stable across identical
                // rebuilds.
                ->orderBy('elementId')
                ->orderBy('siteId')
                ->limit(self::BATCH_SIZE);

            if ($after !== null) {
                [$elementId, $siteId] = $after;
                $query->where(fn ($q) => $q
                    ->where('elementId', '>', $elementId)
                    ->orWhere(fn ($q) => $q->where('elementId', $elementId)->where('siteId', '>', $siteId)));
            }

            $rows = $query->get();

            foreach ($rows as $row) {
                yield [
                    'elementId' => (int) $row->elementId,
                    'siteId' => (int) $row->siteId,
                    'objectId' => (string) $row->objectId,
                    'content' => Json::decodeIfJson($row->content) ?: [],
                    'tokens' => Json::decodeIfJson($row->tokens) ?: [],
                ];
            }

            if ($rows->count() < self::BATCH_SIZE) {
                return;
            }

            $last = $rows->last();
            $after = [(int) $last->elementId, (int) $last->siteId];
        }
    }

    /**
     * Element ids with dirty records, so a build can requery only what changed.
     *
     * @return list<int>
     */
    public function dirtyElementIds(string $indexUid, ?int $limit = null): array
    {
        $query = DB::table(Tables::RECORDS)
            ->distinct()
            ->where(['indexUid' => $indexUid, 'dirty' => true, 'deleted' => false])
            ->orderBy('elementId');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_map('intval', $query->pluck('elementId')->all());
    }

    /**
     * Index uids that have anything waiting to be rebuilt.
     *
     * @return list<string>
     */
    public function indexesWithDirtyRecords(): array
    {
        return DB::table(Tables::RECORDS)
            ->distinct()
            ->where('dirty', true)
            ->pluck('indexUid')
            ->map(fn ($uid) => (string) $uid)
            ->all();
    }

    /**
     * Encoded the way Craft 5's `Json::encode()` did, so rows written before and after an
     * upgrade decode identically.
     */
    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function timestamp(): string
    {
        return (string) Query::prepareDateForDb(now());
    }
}
