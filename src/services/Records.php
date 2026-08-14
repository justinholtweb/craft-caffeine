<?php

namespace justinholtweb\caffeine\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use Generator;
use justinholtweb\caffeine\migrations\Install;
use justinholtweb\caffeine\models\IndexDefinition;
use justinholtweb\caffeine\models\MappedRecord;
use justinholtweb\caffeine\Plugin;

/**
 * The record store: mapped records, their dependency edges, and what is stale.
 *
 * Written with the query builder rather than Active Record throughout. A build touches tens of
 * thousands of rows, and instantiating an AR model per row is most of the cost of doing so.
 */
class Records extends Component
{
    /**
     * Rows per batch when writing. Big enough that the round trips stop mattering, small
     * enough to stay well under MySQL's default max_allowed_packet with fat payloads.
     */
    private const BATCH_SIZE = 500;

    /**
     * Writes a mapped record, replacing whatever was there, and refreshes its dependency edges.
     */
    public function save(IndexDefinition $index, MappedRecord $record): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        // Encoded once: tokenising is the most expensive thing in a build, and the upsert
        // needs the same values in both its insert and update halves.
        $content = Json::encode($record->toArray());
        $tokens = Json::encode(Plugin::getInstance()->tokenizer->tokenizeRecord($index, $record));

        $db->createCommand()->upsert(Install::TABLE_RECORDS, [
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
            'uid' => StringHelper::UUID(),
        ], [
            'objectId' => $record->objectId,
            'content' => $content,
            'tokens' => $tokens,
            'dirty' => false,
            'deleted' => false,
            'dateUpdated' => $now,
        ])->execute();

        $recordId = $this->recordId($index->uid, $record->elementId, $record->siteId);

        if ($recordId !== null) {
            $this->saveDependencies($recordId, $record->dependencies);
        }
    }

    /**
     * Writes many records in batches.
     *
     * @param MappedRecord[] $records
     */
    public function saveAll(IndexDefinition $index, array $records): void
    {
        foreach (array_chunk($records, self::BATCH_SIZE) as $chunk) {
            $transaction = Craft::$app->getDb()->beginTransaction();

            try {
                foreach ($chunk as $record) {
                    $this->save($index, $record);
                }

                $transaction?->commit();
            } catch (\Throwable $e) {
                $transaction?->rollBack();
                throw $e;
            }
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
     * @param list<int> $siteIds
     */
    public function touchElement(string $indexUid, int $elementId, array $siteIds): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        foreach ($siteIds as $siteId) {
            $db->createCommand()->upsert(Install::TABLE_RECORDS, [
                'indexUid' => $indexUid,
                'elementId' => $elementId,
                'siteId' => $siteId,
                'objectId' => $elementId . '-' . $siteId,
                'content' => null,
                'tokens' => null,
                'dirty' => true,
                'deleted' => false,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ], [
                // Only the flags. An existing record keeps its content until the build replaces
                // it, so the artifact stays servable the whole time it is stale.
                'dirty' => true,
                'deleted' => false,
                'dateUpdated' => $now,
            ])->execute();
        }
    }

    public function recordId(string $indexUid, int $elementId, int $siteId): ?int
    {
        $id = (new Query())
            ->select(['id'])
            ->from(Install::TABLE_RECORDS)
            ->where(['indexUid' => $indexUid, 'elementId' => $elementId, 'siteId' => $siteId])
            ->scalar();

        return $id !== false && $id !== null ? (int)$id : null;
    }

    /**
     * Replaces a record's dependency edges.
     *
     * Deleted and re-inserted wholesale rather than diffed: a record's dependencies change
     * whenever its content does, the sets are small, and a diff would need a read anyway.
     *
     * @param list<int> $elementIds
     */
    public function saveDependencies(int $recordId, array $elementIds): void
    {
        $db = Craft::$app->getDb();

        $db->createCommand()
            ->delete(Install::TABLE_DEPS, ['recordId' => $recordId])
            ->execute();

        if ($elementIds === []) {
            return;
        }

        $now = Db::prepareDateForDb(new \DateTime());
        $rows = [];

        foreach (array_unique($elementIds) as $elementId) {
            $rows[] = [$recordId, $elementId, $now, $now, StringHelper::UUID()];
        }

        $db->createCommand()->batchInsert(
            Install::TABLE_DEPS,
            ['recordId', 'elementId', 'dateCreated', 'dateUpdated', 'uid'],
            $rows,
        )->execute();
    }

    /**
     * Marks every record that depends on any of these elements as needing a rebuild.
     *
     * This is the query that keeps denormalised values honest, and the reason `caffeine_deps`
     * carries an index on `elementId` alone.
     *
     * @param list<int> $elementIds
     * @return int Rows marked.
     */
    public function markDirtyByDependency(array $elementIds): int
    {
        if ($elementIds === []) {
            return 0;
        }

        $recordIds = (new Query())
            ->select(['recordId'])
            ->distinct()
            ->from(Install::TABLE_DEPS)
            ->where(['elementId' => $elementIds])
            ->column();

        if ($recordIds === []) {
            return 0;
        }

        return Craft::$app->getDb()->createCommand()
            ->update(Install::TABLE_RECORDS, [
                'dirty' => true,
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            ], ['id' => $recordIds])
            ->execute();
    }

    /**
     * Which indexes hold a record depending on any of these elements.
     *
     * Marking dirty and knowing what to reschedule are two different questions, and answering
     * the second by index rather than by row is what keeps a save from queueing a job for every
     * index on the site.
     *
     * @param list<int> $elementIds
     * @return list<string>
     */
    public function indexUidsDependingOn(array $elementIds): array
    {
        if ($elementIds === []) {
            return [];
        }

        return (new Query())
            ->select(['r.indexUid'])
            ->distinct()
            ->from(['r' => Install::TABLE_RECORDS])
            ->innerJoin(['d' => Install::TABLE_DEPS], '[[d.recordId]] = [[r.id]]')
            ->where(['d.elementId' => $elementIds])
            ->column();
    }

    public function markIndexDirty(string $indexUid): int
    {
        return Craft::$app->getDb()->createCommand()
            ->update(Install::TABLE_RECORDS, [
                'dirty' => true,
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            ], ['indexUid' => $indexUid])
            ->execute();
    }

    /**
     * Flags records as gone without removing them.
     *
     * Kept rather than deleted so the next publish knows the artifact changed and can rebuild
     * from the store alone. `purgeDeleted()` clears them once that has happened.
     *
     * @param list<int> $elementIds
     */
    public function markDeleted(string $indexUid, array $elementIds): int
    {
        if ($elementIds === []) {
            return 0;
        }

        return Craft::$app->getDb()->createCommand()
            ->update(Install::TABLE_RECORDS, [
                'deleted' => true,
                'dirty' => true,
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            ], ['indexUid' => $indexUid, 'elementId' => $elementIds])
            ->execute();
    }

    public function purgeDeleted(string $indexUid): int
    {
        return Craft::$app->getDb()->createCommand()
            ->delete(Install::TABLE_RECORDS, ['indexUid' => $indexUid, 'deleted' => true])
            ->execute();
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
     * @param list<string> $keepUids Uids currently defined in project config.
     * @return int Rows removed.
     */
    public function deleteOrphans(array $keepUids): int
    {
        $condition = $keepUids === []
            ? ['not', ['indexUid' => null]]
            : ['not', ['indexUid' => $keepUids]];

        return Craft::$app->getDb()->createCommand()
            ->delete(Install::TABLE_RECORDS, $condition)
            ->execute();
    }

    public function deleteForIndex(string $indexUid): int
    {
        return Craft::$app->getDb()->createCommand()
            ->delete(Install::TABLE_RECORDS, ['indexUid' => $indexUid])
            ->execute();
    }

    /**
     * Removes records for elements the index no longer covers.
     *
     * The complement of `markDeleted`: an entry that was moved to a different section is not
     * deleted, it simply stopped qualifying, and nothing would ever notice without this.
     *
     * @param list<int> $keepElementIds
     */
    public function pruneMissing(string $indexUid, array $keepElementIds): int
    {
        $condition = ['indexUid' => $indexUid];

        if ($keepElementIds !== []) {
            $condition = ['and', $condition, ['not', ['elementId' => $keepElementIds]]];
        }

        return Craft::$app->getDb()->createCommand()
            ->delete(Install::TABLE_RECORDS, $condition)
            ->execute();
    }

    public function countFor(string $indexUid, bool $dirtyOnly = false): int
    {
        $query = (new Query())
            ->from(Install::TABLE_RECORDS)
            ->where(['indexUid' => $indexUid, 'deleted' => false]);

        if ($dirtyOnly) {
            $query->andWhere(['dirty' => true]);
        }

        return (int)$query->count();
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
        $query = (new Query())
            ->select(['elementId', 'siteId', 'objectId', 'content', 'tokens'])
            ->from(Install::TABLE_RECORDS)
            // `content is not null` excludes the stubs `touchElement()` writes for elements that
            // have been marked but not yet built. Without it a publish between the two would put
            // an empty hit in the artifact.
            ->where(['and', ['indexUid' => $indexUid, 'deleted' => false], ['not', ['content' => null]]])
            // Ordered so a build is reproducible: the record at position 12 is the same record
            // on every run, which keeps postings lists stable across identical rebuilds.
            ->orderBy(['elementId' => SORT_ASC, 'siteId' => SORT_ASC]);

        foreach ($query->batch(self::BATCH_SIZE) as $rows) {
            foreach ($rows as $row) {
                yield [
                    'elementId' => (int)$row['elementId'],
                    'siteId' => (int)$row['siteId'],
                    'objectId' => (string)$row['objectId'],
                    'content' => Json::decodeIfJson($row['content']) ?: [],
                    'tokens' => Json::decodeIfJson($row['tokens']) ?: [],
                ];
            }
        }
    }

    /**
     * Element ids with dirty records, so a build can requery only what changed.
     *
     * @return list<int>
     */
    public function dirtyElementIds(string $indexUid, ?int $limit = null): array
    {
        $query = (new Query())
            ->select(['elementId'])
            ->distinct()
            ->from(Install::TABLE_RECORDS)
            ->where(['indexUid' => $indexUid, 'dirty' => true, 'deleted' => false])
            ->orderBy(['elementId' => SORT_ASC]);

        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_map('intval', $query->column());
    }

    /**
     * Index uids that have anything waiting to be rebuilt.
     *
     * @return list<string>
     */
    public function indexesWithDirtyRecords(): array
    {
        return (new Query())
            ->select(['indexUid'])
            ->distinct()
            ->from(Install::TABLE_RECORDS)
            ->where(['dirty' => true])
            ->column();
    }
}
