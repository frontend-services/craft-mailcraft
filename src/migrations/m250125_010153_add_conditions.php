<?php

namespace frontendservices\mailcraft\migrations;

use Craft;
use craft\db\Migration;

/**
 * m250125_010153_add_conditions migration.
 * Add condition1 and condition2 string columns to the mailcraft_emailtemplates table.
 */
class m250125_010153_add_conditions extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->addColumn('{{%mailcraft_emailtemplates}}', 'condition1', $this->text()->after('template'));
        $this->addColumn('{{%mailcraft_emailtemplates}}', 'condition2', $this->text()->after('condition1'));

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropColumn('{{%mailcraft_emailtemplates}}', 'condition1');
        $this->dropColumn('{{%mailcraft_emailtemplates}}', 'condition2');

        return true;
    }
}
