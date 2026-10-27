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
        $this->dropTableIfExists('{{%mailcraft_statuses}}');
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
            'channels' => $this->text(),
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

        $this->createTable('{{%mailcraft_statuses}}', [
            'id' => $this->primaryKey(),
            'templateId' => $this->integer(),
            'templateTitle' => $this->string(255),
            'channel' => $this->string(64)->notNull(),
            'status' => $this->string(16)->notNull(),
            'subject' => $this->string(255),
            'recipient' => $this->string(255),
            'payload' => $this->mediumText()->notNull(),
            'error' => $this->text(),
            'attempts' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
            'jobId' => $this->string(64),
            'resentFromId' => $this->integer(),
            'dateSent' => $this->dateTime(),
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

        foreach (['channel', 'status', 'dateCreated', 'dateUpdated', 'resentFromId', 'templateId'] as $column) {
            $this->createIndex(null, '{{%mailcraft_statuses}}', $column, false);
        }
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

        $this->addForeignKey(
            null,
            '{{%mailcraft_statuses}}',
            'templateId',
            '{{%mailcraft_emailtemplates}}',
            'id',
            'SET NULL',
            null
        );

        $this->addForeignKey(
            null,
            '{{%mailcraft_statuses}}',
            'resentFromId',
            '{{%mailcraft_statuses}}',
            'id',
            'SET NULL',
            null
        );
    }
}
