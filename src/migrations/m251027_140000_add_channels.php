<?php

namespace frontendservices\mailcraft\migrations;

use Craft;
use craft\db\Migration;

/**
 * Adds the `channels` column (delivery channels enabled per template) and marks every
 * existing template as sent by email, which is what they did before channels existed.
 */
class m251027_140000_add_channels extends Migration
{
    private const TABLE = '{{%mailcraft_emailtemplates}}';

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::TABLE, 'channels')) {
            $this->addColumn(self::TABLE, 'channels', $this->text()->after('condition2'));
        }

        $this->update(
            self::TABLE,
            ['channels' => '{"email":{"enabled":true}}'],
            ['channels' => null],
            [],
            false
        );

        Craft::$app->db->schema->refresh();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::TABLE, 'channels')) {
            $this->dropColumn(self::TABLE, 'channels');
        }

        return true;
    }
}
