<?php

namespace frontendservices\mailcraft\migrations;

use Craft;
use craft\db\Migration;

/**
 * m250416_151451_add_data_table migration.
 */
class m250416_151451_add_data_table extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%mailcraft_data}}')) {
            $this->createTable('{{%mailcraft_data}}', [
                'id' => $this->primaryKey(),
                'key' => $this->string(255)->notNull(),
                'value' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // Create a unique index on the key column
            $this->createIndex(
                null,
                '{{%mailcraft_data}}',
                'key',
                true
            );
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%mailcraft_data}}');
        return true;
    }
}