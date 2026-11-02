<?php

namespace frontendservices\mailcraft\migrations;

use craft\db\Migration;
use craft\helpers\Db;
use craft\helpers\MigrationHelper;
use yii\db\Expression;

/**
 * Adds `dateSeen` to private messages ("new since your last inbox visit") and backfills it so
 * nothing that exists at upgrade time counts as new.
 */
class m251030_120000_add_date_seen extends Migration
{
    private const MESSAGES = '{{%mailcraft_messages}}';

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::MESSAGES, 'dateSeen')) {
            $this->addColumn(self::MESSAGES, 'dateSeen', $this->dateTime()->null()->after('dateRead'));
        }

        // always: a run that stopped after adding the column can be re-run
        $this->update(
            self::MESSAGES,
            ['dateSeen' => new Expression('COALESCE([[dateRead]], [[dateCreated]])')],
            ['dateSeen' => null],
            [],
            false
        );

        if (Db::findIndex(self::MESSAGES, ['toId', 'dateSeen'], false, $this->db) === null) {
            $this->createIndex(null, self::MESSAGES, 'toId,dateSeen', false);
        }

        $this->db->getSchema()->refresh();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::MESSAGES, 'dateSeen')) {
            MigrationHelper::dropIndexIfExists(self::MESSAGES, ['toId', 'dateSeen'], false, $this);
            $this->dropColumn(self::MESSAGES, 'dateSeen');
        }

        return true;
    }
}
