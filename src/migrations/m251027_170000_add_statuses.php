<?php

namespace frontendservices\mailcraft\migrations;

use Craft;
use craft\db\Migration;

/**
 * Adds the `mailcraft_statuses` table that backs the opt-in delivery log.
 */
class m251027_170000_add_statuses extends Migration
{
    private const TABLE = '{{%mailcraft_statuses}}';

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            return true;
        }

        $this->createTable(self::TABLE, [
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

        $this->createIndex(null, self::TABLE, 'channel', false);
        $this->createIndex(null, self::TABLE, 'status', false);
        $this->createIndex(null, self::TABLE, 'dateCreated', false);
        $this->createIndex(null, self::TABLE, 'dateUpdated', false);
        $this->createIndex(null, self::TABLE, 'resentFromId', false);
        $this->createIndex(null, self::TABLE, 'templateId', false);

        $this->addForeignKey(null, self::TABLE, 'templateId', '{{%mailcraft_emailtemplates}}', 'id', 'SET NULL', null);
        $this->addForeignKey(null, self::TABLE, 'resentFromId', self::TABLE, 'id', 'SET NULL', null);

        Craft::$app->db->schema->refresh();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE);

        return true;
    }
}
