<?php

namespace frontendservices\mailcraft\records;

use craft\db\ActiveRecord;

/**
 * Email template record.
 *
 * @property int $id
 * @property string $subject
 * @property string $event
 * @property int|null $delay
 * @property string $template
 * @property string|null $to
 * @property string|null $toName
 * @property string|null $cc
 * @property string|null $bcc
 * @property string|null $from
 * @property string|null $fromName
 * @property string|null $replyTo
 * @property string|null $conditions
 * @property string|null $condition1
 * @property string|null $condition2
 */
class EmailTemplateRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%mailcraft_emailtemplates}}';
    }
}