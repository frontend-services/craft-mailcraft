<?php

namespace frontendservices\mailcraft\records;

use craft\db\ActiveRecord;

/**
 * Send (bulk send to an audience) record.
 *
 * @property int $id
 * @property int|null $templateId
 * @property string|null $templateTitle
 * @property string|null $templateSnapshot JSON
 * @property string|null $subject
 * @property string|null $audience JSON
 * @property string|null $audienceSummary
 * @property string $channels JSON list of channel handles
 * @property int|null $senderId
 * @property int|null $siteId
 * @property string $status `queued`, `running`, `done`, `failed` or `cancelled`
 * @property int|null $total
 * @property int $processed
 * @property int $sent
 * @property int $failed
 * @property int $lastUserId
 * @property string|null $jobId
 * @property string|null $error
 * @property string|null $dateStarted
 * @property string|null $dateFinished
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class SendRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%mailcraft_sends}}';
    }
}
