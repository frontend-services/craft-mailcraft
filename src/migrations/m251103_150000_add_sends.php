<?php

namespace frontendservices\mailcraft\migrations;

use craft\db\Migration;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\MigrationHelper;

/**
 * Adds the sends table (bulk sends to an audience) and `statuses.sendId`.
 */
class m251103_150000_add_sends extends Migration
{
    private const SENDS = '{{%mailcraft_sends}}';
    private const STATUSES = '{{%mailcraft_statuses}}';
    private const TEMPLATES = '{{%mailcraft_emailtemplates}}';

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::SENDS)) {
            $this->createTable(self::SENDS, [
                'id' => $this->primaryKey(),
                'templateId' => $this->integer(),
                'templateTitle' => $this->string(255),
                'templateSnapshot' => $this->text(),
                'subject' => $this->string(255),
                'audience' => $this->text(),
                'audienceSummary' => $this->string(255),
                'channels' => $this->text()->notNull(),
                'senderId' => $this->integer(),
                'siteId' => $this->integer(),
                'status' => $this->string(16)->notNull(),
                'total' => $this->integer(),
                'processed' => $this->integer()->notNull()->defaultValue(0),
                'sent' => $this->integer()->notNull()->defaultValue(0),
                'failed' => $this->integer()->notNull()->defaultValue(0),
                'lastUserId' => $this->integer()->notNull()->defaultValue(0),
                'jobId' => $this->string(64),
                'error' => $this->text(),
                'dateStarted' => $this->dateTime(),
                'dateFinished' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        foreach (['status', 'dateCreated'] as $column) {
            if (Db::findIndex(self::SENDS, [$column], false, $this->db) === null) {
                $this->createIndex(null, self::SENDS, $column, false);
            }
        }

        if (!$this->hasForeignKey(self::SENDS, 'templateId')) {
            $this->addForeignKey(null, self::SENDS, 'templateId', self::TEMPLATES, 'id', 'SET NULL', null);
        }
        if (!$this->hasForeignKey(self::SENDS, 'senderId')) {
            $this->addForeignKey(null, self::SENDS, 'senderId', Table::USERS, 'id', 'SET NULL', null);
        }

        if (!$this->db->columnExists(self::STATUSES, 'sendId')) {
            $this->addColumn(self::STATUSES, 'sendId', $this->integer()->null()->after('resentFromId'));
        }
        if (Db::findIndex(self::STATUSES, ['sendId'], false, $this->db) === null) {
            $this->createIndex(null, self::STATUSES, 'sendId', false);
        }
        if (!$this->hasForeignKey(self::STATUSES, 'sendId')) {
            $this->addForeignKey(null, self::STATUSES, 'sendId', self::SENDS, 'id', 'SET NULL', null);
        }

        $this->db->getSchema()->refresh();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::STATUSES, 'sendId')) {
            MigrationHelper::dropForeignKeyIfExists(self::STATUSES, ['sendId'], $this);
            MigrationHelper::dropIndexIfExists(self::STATUSES, ['sendId'], false, $this);
            $this->dropColumn(self::STATUSES, 'sendId');
        }

        $this->dropTableIfExists(self::SENDS);

        return true;
    }

    /**
     * Whether the table has a foreign key on the column.
     */
    private function hasForeignKey(string $table, string $column): bool
    {
        $schema = $this->db->getSchema()->getTableSchema($table, true);
        if ($schema === null) {
            return false;
        }
        foreach ($schema->foreignKeys as $fk) {
            if (array_key_exists($column, $fk)) {
                return true;
            }
        }

        return false;
    }
}
