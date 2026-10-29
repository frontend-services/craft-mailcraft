<?php

namespace frontendservices\mailcraft\migrations;

use craft\db\Migration;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\MigrationHelper;

/**
 * Adds the `mailcraft_messages` table (private messages) and the template `handle` column.
 */
class m251028_120000_add_private_messages extends Migration
{
    private const MESSAGES = '{{%mailcraft_messages}}';
    private const TEMPLATES = '{{%mailcraft_emailtemplates}}';

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::MESSAGES)) {
            $this->createTable(self::MESSAGES, [
                'id' => $this->primaryKey(),
                'toId' => $this->integer(),
                'fromId' => $this->integer(),
                'templateId' => $this->integer(),
                'siteId' => $this->integer(),
                'type' => $this->string(100),
                'subject' => $this->string(255)->notNull(),
                'content' => $this->mediumText()->notNull(),
                'pinned' => $this->boolean()->notNull()->defaultValue(false),
                'hidden' => $this->boolean()->notNull()->defaultValue(false),
                'dateRead' => $this->dateTime(),
                'dateExpires' => $this->dateTime(),
                'meta1' => $this->string(255),
                'meta2' => $this->string(255),
                'meta3' => $this->string(255),
                'meta4' => $this->string(255),
                'meta5' => $this->string(255),
                'data' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, self::MESSAGES, 'toId,hidden,pinned,dateCreated', false);
            foreach (['fromId', 'type', 'dateExpires', 'meta1', 'meta2', 'meta3', 'meta4', 'meta5'] as $column) {
                $this->createIndex(null, self::MESSAGES, $column, false);
            }

            $this->addForeignKey(null, self::MESSAGES, 'toId', Table::ELEMENTS, 'id', 'CASCADE', null);
            $this->addForeignKey(null, self::MESSAGES, 'fromId', Table::ELEMENTS, 'id', 'SET NULL', null);
            $this->addForeignKey(null, self::MESSAGES, 'templateId', self::TEMPLATES, 'id', 'SET NULL', null);
            $this->addForeignKey(null, self::MESSAGES, 'siteId', Table::SITES, 'id', 'SET NULL', null);
        }

        if (!$this->db->columnExists(self::TEMPLATES, 'handle')) {
            $this->addColumn(self::TEMPLATES, 'handle', $this->string(255)->null()->after('event'));
        }
        // guarded on its own so a run that stopped after adding the column can be re-run
        if (Db::findIndex(self::TEMPLATES, ['handle'], true, $this->db) === null) {
            $this->createIndex(null, self::TEMPLATES, 'handle', true);
        }

        $this->db->getSchema()->refresh();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::MESSAGES);

        if ($this->db->columnExists(self::TEMPLATES, 'handle')) {
            MigrationHelper::dropIndexIfExists(self::TEMPLATES, ['handle'], true, $this);
            $this->dropColumn(self::TEMPLATES, 'handle');
        }

        return true;
    }
}
