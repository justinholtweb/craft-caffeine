<?php

namespace justinholtweb\caffeine\migrations;

use craft\db\Migration;

/**
 * Records the definition fingerprint each artifact was published from.
 *
 * Existing rows are left null, which `Indexes::checkDefinition()` reads as "nothing to compare",
 * so upgrading does not rebuild every index. The next publish records a fingerprint.
 */
class m261020_000000_definition_hash extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(Install::TABLE_ARTIFACTS, 'definitionHash')) {
            $this->addColumn(Install::TABLE_ARTIFACTS, 'definitionHash', $this->string(16)->after('buildMs'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(Install::TABLE_ARTIFACTS, 'definitionHash')) {
            $this->dropColumn(Install::TABLE_ARTIFACTS, 'definitionHash');
        }

        return true;
    }
}
