<?php

namespace justinholtweb\caffeine\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Installs Caffeine's record store, dependency map and artifact ledger.
 *
 * Index *definitions* are not here — they live in project config. These tables hold only what
 * is derived from them, which is why every one of them can be dropped and rebuilt from content.
 */
class Install extends Migration
{
    public const TABLE_RECORDS = '{{%caffeine_records}}';
    public const TABLE_DEPS = '{{%caffeine_deps}}';
    public const TABLE_ARTIFACTS = '{{%caffeine_artifacts}}';

    public function safeUp(): bool
    {
        $this->createRecordsTable();
        $this->createDepsTable();
        $this->createArtifactsTable();

        return true;
    }

    public function safeDown(): bool
    {
        // Deps first — it carries a foreign key into records.
        $this->dropTableIfExists(self::TABLE_DEPS);
        $this->dropTableIfExists(self::TABLE_RECORDS);
        $this->dropTableIfExists(self::TABLE_ARTIFACTS);

        return true;
    }

    /**
     * One row per element per site per index.
     *
     * Per site because a facet value in French is not the same value as its English twin, and
     * collapsing them would merge two legitimately different buckets.
     */
    private function createRecordsTable(): void
    {
        if ($this->db->tableExists(self::TABLE_RECORDS)) {
            return;
        }

        $this->createTable(self::TABLE_RECORDS, [
            'id' => $this->primaryKey(),
            // The index's project-config uid, not its handle: a handle rename should carry the
            // records with it rather than orphaning them.
            'indexUid' => $this->string(36)->notNull(),
            'elementId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            // Stable public identifier for the record, exposed as `objectID` in hits to match
            // the Algolia response shape.
            'objectId' => $this->string(64)->notNull(),
            // The mapped record: facet values, sortable values and payload, already transformed.
            'content' => $this->longText(),
            // Tokenised searchable text, built in PHP so the browser never needs a tokeniser.
            'tokens' => $this->longText(),
            // Set when the element changes, or when something the record depends on changes.
            // The build walks dirty rows only.
            'dirty' => $this->boolean()->notNull()->defaultValue(true),
            // Records for elements that have gone away, kept until the next publish so the
            // artifact can be rebuilt without re-walking everything.
            'deleted' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::TABLE_RECORDS, ['indexUid', 'elementId', 'siteId'], true);
        $this->createIndex(null, self::TABLE_RECORDS, ['indexUid', 'dirty']);
        $this->createIndex(null, self::TABLE_RECORDS, ['indexUid', 'deleted']);
        $this->createIndex(null, self::TABLE_RECORDS, ['elementId']);

        $this->addForeignKey(null, self::TABLE_RECORDS, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, self::TABLE_RECORDS, ['siteId'], Table::SITES, ['id'], 'CASCADE', null);
    }

    /**
     * Reverse dependency map: every element that was read to build a record.
     *
     * This is what stops denormalised data going stale. A record that copies a category's title
     * into a facet label depends on that category; renaming it has to mark the record dirty, or
     * the artifact serves a label that no longer exists anywhere in the CMS. Craft solves the
     * same problem the same way with `templatecacheelements`.
     */
    private function createDepsTable(): void
    {
        if ($this->db->tableExists(self::TABLE_DEPS)) {
            return;
        }

        $this->createTable(self::TABLE_DEPS, [
            'id' => $this->primaryKey(),
            'recordId' => $this->integer()->notNull(),
            'elementId' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::TABLE_DEPS, ['recordId', 'elementId'], true);
        // The hot path: "this element just changed — which records care?"
        $this->createIndex(null, self::TABLE_DEPS, ['elementId']);

        $this->addForeignKey(null, self::TABLE_DEPS, ['recordId'], self::TABLE_RECORDS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, self::TABLE_DEPS, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE', null);
    }

    /**
     * One row per published artifact version.
     *
     * Superseded versions stay listed until pruned, because a visitor mid-session is still
     * fetching the version the page was rendered against.
     */
    private function createArtifactsTable(): void
    {
        if ($this->db->tableExists(self::TABLE_ARTIFACTS)) {
            return;
        }

        $this->createTable(self::TABLE_ARTIFACTS, [
            'id' => $this->primaryKey(),
            'indexUid' => $this->string(36)->notNull(),
            // Monotonic per index, and the number that appears in the payload filename.
            'version' => $this->integer()->unsigned()->notNull(),
            'status' => $this->string(32)->notNull()->defaultValue('building'),
            'recordCount' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'byteSize' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0),
            // Content hash of the payload, so an identical rebuild can skip republishing.
            'checksum' => $this->string(64),
            // Relative paths of every file this version wrote, so pruning is exact rather than
            // a glob that might catch a neighbour's files.
            'files' => $this->text(),
            'buildMs' => $this->integer()->unsigned(),
            'error' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::TABLE_ARTIFACTS, ['indexUid', 'version'], true);
        $this->createIndex(null, self::TABLE_ARTIFACTS, ['indexUid', 'status']);
    }
}
