<?php

declare(strict_types=1);

use CraftCms\Cms\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use JustinHoltWeb\Caffeine\Database\Tables;

/**
 * Records the definition fingerprint each artifact was published from.
 *
 * Named exactly as it was in Caffeine 5, so a site upgraded from 5.x already has it logged as
 * applied. Idempotent regardless: the install migration creates the column on a fresh install,
 * and a 5.x site that never ran it gets it here.
 *
 * Existing rows are left null, which `Indexes::checkDefinition()` reads as "nothing to compare",
 * so upgrading does not rebuild every index. The next publish records a fingerprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable(Tables::ARTIFACTS) || Schema::hasColumn(Tables::ARTIFACTS, 'definitionHash')) {
            return;
        }

        Schema::table(Tables::ARTIFACTS, function (Blueprint $table) {
            $table->string('definitionHash', 16)->nullable()->after('buildMs');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(Tables::ARTIFACTS) || ! Schema::hasColumn(Tables::ARTIFACTS, 'definitionHash')) {
            return;
        }

        Schema::table(Tables::ARTIFACTS, function (Blueprint $table) {
            $table->dropColumn('definitionHash');
        });
    }
};
