<?php

namespace frontendservices\mailcraft\records;

use craft\db\ActiveRecord;

/**
 * Private message record.
 *
 * @property int $id
 * @property int|null $toId
 * @property int|null $fromId
 * @property int|null $templateId
 * @property int|null $siteId
 * @property string|null $type
 * @property string $subject
 * @property string $content
 * @property bool $pinned
 * @property bool $hidden
 * @property string|null $dateRead
 * @property string|null $dateExpires
 * @property string|null $meta1
 * @property string|null $meta2
 * @property string|null $meta3
 * @property string|null $meta4
 * @property string|null $meta5
 * @property string|null $data JSON
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class MessageRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%mailcraft_messages}}';
    }
}
