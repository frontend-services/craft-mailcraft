<?php

namespace frontendservices\mailcraft\jobs;

use Craft;
use craft\helpers\Db;
use craft\queue\BaseJob;
use DateTime;
use frontendservices\mailcraft\MailCraft;
use frontendservices\mailcraft\models\Send;
use frontendservices\mailcraft\records\SendRecord;
use frontendservices\mailcraft\services\SendService;
use yii\queue\RetryableJobInterface;

/**
 * Delivers one send (Send page) to its audience.
 */
class SendToAudienceJob extends BaseJob implements RetryableJobInterface
{
    public int $sendId = 0;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $sends = MailCraft::getInstance()->sends;

        $send = $sends->getById($this->sendId);
        if ($send === null) {
            return;
        }

        if (in_array($send->status, [Send::STATUS_CANCELLED, Send::STATUS_DONE], true)) {
            return;
        }

        $sends->run($send, fn($p, $l) => $this->setProgress($queue, $p, $l));
    }

    /**
     * @inheritdoc
     */
    public function getTtr(): int
    {
        return SendService::TTR;
    }

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        $retry = $attempt < SendService::MAX_ATTEMPTS;
        if (!$retry) {
            Db::update(SendRecord::tableName(), [
                'status' => Send::STATUS_FAILED,
                'error' => $error instanceof \Throwable ? $error->getMessage() : 'Gave up after ' . $attempt . ' attempts',
                'dateFinished' => Db::prepareDateForDb(new DateTime()),
            ], ['id' => $this->sendId]);
        }

        return $retry;
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return Craft::t('mailcraft', 'Sending to users');
    }
}
