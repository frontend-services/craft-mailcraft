<?php

namespace frontendservices\mailcraft\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table;

/**
 * Install migration.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if ($this->createTables()) {
            $this->createIndexes();
            $this->addForeignKeys();

            // Refresh the db schema caches
            Craft::$app->db->schema->refresh();
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%mailcraft_emailtemplates}}');
        $this->dropTableIfExists('{{%mailcraft_data}}');
        return true;
    }

    /**
     * Creates the tables.
     */
    protected function createTables(): bool
    {
        $tableSchema = Craft::$app->db->schema->getTableSchema('{{%mailcraft_emailtemplates}}');
        if ($tableSchema !== null) {
            return false;
        }

        $this->createTable('{{%mailcraft_emailtemplates}}', [
            'id' => $this->primaryKey(),
            'subject' => $this->string()->notNull(),
            'event' => $this->string()->notNull(),
            'delay' => $this->integer()->unsigned(),
            'template' => $this->text()->notNull(),
            'conditions' => $this->text(),
            'condition1' => $this->text(),
            'condition2' => $this->text(),
            'to' => $this->string(),
            'toName' => $this->string(),
            'cc' => $this->string(),
            'bcc' => $this->string(),
            'from' => $this->string(),
            'fromName' => $this->string(),
            'replyTo' => $this->string(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%mailcraft_data}}', [
            'id' => $this->primaryKey(),
            'key' => $this->string(255)->notNull(),
            'value' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        return true;
    }

    /**
     * Creates the indexes.
     */
    protected function createIndexes(): void
    {
        $this->createIndex(
            null,
            '{{%mailcraft_emailtemplates}}',
            'event',
            false
        );

        $this->createIndex(
            null,
            '{{%mailcraft_data}}',
            'key',
            true
        );
    }

    /**
     * Adds the foreign keys.
     */
    protected function addForeignKeys(): void
    {
        $this->addForeignKey(
            null,
            '{{%mailcraft_emailtemplates}}',
            'id',
            Table::ELEMENTS,
            'id',
            'CASCADE',
            null
        );
    }
}
