<?php

namespace frontendservices\mailcraft\records;

use craft\db\ActiveRecord;

/**
 * Status (delivery log) record.
 *
 * @property int $id
 * @property int|null $templateId
 * @property string|null $templateTitle
 * @property string $channel
 * @property string $status `queued`, `sent` or `failed`
 * @property string|null $subject
 * @property string|null $recipient
 * @property string $payload JSON
 * @property string|null $error
 * @property int $attempts
 * @property string|null $jobId
 * @property int|null $resentFromId
 * @property string|null $dateSent
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class StatusRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%mailcraft_statuses}}';
    }
}
