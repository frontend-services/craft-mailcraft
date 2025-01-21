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