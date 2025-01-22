<?php

namespace frontendservices\mailcraft\migrations;

use Craft;
use craft\db\Migration;

/**
 * m250121_234511_add_conditions migration.
 */
class m250121_234511_add_conditions extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->addColumn('{{%mailcraft_emailtemplates}}', 'conditions', $this->text()->after('template'));

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropColumn('{{%mailcraft_emailtemplates}}', 'conditions');
        return false;
    }
}
