<?php

declare(strict_types=1);

namespace JustinHoltWeb\Caffeine\Database\Migrations;

use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\Database\Table;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use JustinHoltWeb\Caffeine\Database\Tables;

/**
 * Installs Caffeine's record store, dependency map and artifact ledger.
 *
 * Index *definitions* are not here — they live in project config. These tables hold only what
 * is derived from them, which is why every one of them can be dropped and rebuilt from content.
 *
 * Each table is skipped when it already exists. A site upgraded from Caffeine 5 keeps the tables
 * it had, so their rows survive, and nothing here tries to create them a second time.
 *
 * Columns mirror the Craft 5 schema exactly: `integer('id', true)` primary keys, so the foreign
 * keys between Caffeine's own tables and into `elements`/`sites` (also `integer`) line up.
 */
class Install extends Migration
{
    public function up(): void
    {
        $this->createRecordsTable();
        $this->createDepsTable();
        $this->createArtifactsTable();
    }

    public function down(): void
    {
        // Deps first — it carries a foreign key into records.
        Schema::dropIfExists(Tables::DEPS);
        Schema::dropIfExists(Tables::RECORDS);
        Schema::dropIfExists(Tables::ARTIFACTS);
    }

    /**
     * One row per element per site per index.
     *
     * Per site because a facet value in French is not the same value as its English twin, and
     * collapsing them would merge two legitimately different buckets.
     */
    private function createRecordsTable(): void
    {
        if (Schema::hasTable(Tables::RECORDS)) {
            return;
        }

        Schema::create(Tables::RECORDS, function (Blueprint $table) {
            $table->integer('id', true);
            // The index's project-config uid, not its handle: a handle rename should carry the
            // records with it rather than orphaning them.
            $table->string('indexUid', 36);
            $table->integer('elementId');
            $table->integer('siteId');
            // Stable public identifier for the record, exposed as `objectID` in hits to match
            // the Algolia response shape.
            $table->string('objectId', 64);
            // The mapped record: facet values, sortable values and payload, already transformed.
            $table->longText('content')->nullable();
            // Tokenised searchable text, built in PHP so the browser never needs a tokeniser.
            $table->longText('tokens')->nullable();
            // Set when the element changes, or when something the record depends on changes.
            // The build walks dirty rows only.
            $table->boolean('dirty')->default(true);
            // Records for elements that have gone away, kept until the next publish so the
            // artifact can be rebuilt without re-walking everything.
            $table->boolean('deleted')->default(false);
            $table->dateTime('dateCreated');
            $table->dateTime('dateUpdated');
            $table->char('uid', 36)->default('0');

            $table->unique(['indexUid', 'elementId', 'siteId']);
            $table->index(['indexUid', 'dirty']);
            $table->index(['indexUid', 'deleted']);
            $table->index(['elementId']);

            $table->foreign('elementId')->references('id')->on(Table::ELEMENTS)->cascadeOnDelete();
            $table->foreign('siteId')->references('id')->on(Table::SITES)->cascadeOnDelete();
        });
    }

    /**
     * Reverse dependency map: every element that was read to build a record.
     *
     * This is what stops denormalised data going stale. A record that copies a category's title
     * into a facet label depends on that category; renaming it has to mark the record dirty, or
     * the artifact serves a label that no longer exists anywhere in the CMS. Craft solves the
     * same problem the same way with its element cache tags.
     */
    private function createDepsTable(): void
    {
        if (Schema::hasTable(Tables::DEPS)) {
            return;
        }

        Schema::create(Tables::DEPS, function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('recordId');
            $table->integer('elementId');
            $table->dateTime('dateCreated');
            $table->dateTime('dateUpdated');
            $table->char('uid', 36)->default('0');

            $table->unique(['recordId', 'elementId']);
            // The hot path: "this element just changed — which records care?"
            $table->index(['elementId']);

            $table->foreign('recordId')->references('id')->on(Tables::RECORDS)->cascadeOnDelete();
            $table->foreign('elementId')->references('id')->on(Table::ELEMENTS)->cascadeOnDelete();
        });
    }

    /**
     * One row per published artifact version.
     *
     * Superseded versions stay listed until pruned, because a visitor mid-session is still
     * fetching the version the page was rendered against.
     */
    private function createArtifactsTable(): void
    {
        if (Schema::hasTable(Tables::ARTIFACTS)) {
            return;
        }

        Schema::create(Tables::ARTIFACTS, function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('indexUid', 36);
            // Monotonic per index, and the number that appears in the payload filename.
            $table->unsignedInteger('version');
            $table->string('status', 32)->default('building');
            $table->unsignedInteger('recordCount')->default(0);
            $table->unsignedBigInteger('byteSize')->default(0);
            // Content hash of the payload, so an identical rebuild can skip republishing.
            $table->string('checksum', 64)->nullable();
            // Relative paths of every file this version wrote, so pruning is exact rather than
            // a glob that might catch a neighbour's files.
            $table->text('files')->nullable();
            $table->unsignedInteger('buildMs')->nullable();
            // IndexDefinition::fingerprint() at publish, so a build can tell the definition has
            // changed in ways project config never saw — module-added attributes.
            $table->string('definitionHash', 16)->nullable();
            $table->text('error')->nullable();
            $table->dateTime('dateCreated');
            $table->dateTime('dateUpdated');
            $table->char('uid', 36)->default('0');

            $table->unique(['indexUid', 'version']);
            $table->index(['indexUid', 'status']);
        });
    }
}
