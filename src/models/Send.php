<?php

namespace frontendservices\mailcraft\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\queue\Queue;
use DateTime;
use frontendservices\mailcraft\records\SendRecord;

/**
 * A bulk send of a template to an audience of users.
 */
class Send extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public ?int $id = null;
    public ?int $templateId = null;
    public ?string $templateTitle = null;
    public ?array $templateSnapshot = null;
    public ?string $subject = null;
    public array $audience = [];
    public ?string $audienceSummary = null;
    public array $channels = [];
    public ?int $senderId = null;
    public ?int $siteId = null;
    public string $status = self::STATUS_QUEUED;
    public ?int $total = null;
    public int $processed = 0;
    public int $sent = 0;
    public int $failed = 0;
    public int $lastUserId = 0;
    public ?string $jobId = null;
    public ?string $error = null;
    public ?DateTime $dateStarted = null;
    public ?DateTime $dateFinished = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /**
     * Builds a model from its record.
     */
    public static function fromRecord(SendRecord $r): self
    {
        $snapshot = Json::decodeIfJson((string)$r->templateSnapshot);
        $audience = Json::decodeIfJson((string)$r->audience);
        $channels = Json::decodeIfJson((string)$r->channels);

        $model = new self();
        $model->id = $r->id !== null ? (int)$r->id : null;
        $model->templateId = $r->templateId !== null ? (int)$r->templateId : null;
        $model->templateTitle = $r->templateTitle;
        $model->templateSnapshot = is_array($snapshot) ? $snapshot : null;
        $model->subject = $r->subject;
        $model->audience = is_array($audience) ? $audience : [];
        $model->audienceSummary = $r->audienceSummary;
        $model->channels = is_array($channels) ? $channels : [];
        $model->senderId = $r->senderId !== null ? (int)$r->senderId : null;
        $model->siteId = $r->siteId !== null ? (int)$r->siteId : null;
        $model->status = (string)$r->status;
        $model->total = $r->total !== null ? (int)$r->total : null;
        $model->processed = (int)$r->processed;
        $model->sent = (int)$r->sent;
        $model->failed = (int)$r->failed;
        $model->lastUserId = (int)$r->lastUserId;
        $model->jobId = $r->jobId;
        $model->error = $r->error;
        $model->dateStarted = DateTimeHelper::toDateTime($r->dateStarted) ?: null;
        $model->dateFinished = DateTimeHelper::toDateTime($r->dateFinished) ?: null;
        $model->dateCreated = DateTimeHelper::toDateTime($r->dateCreated) ?: null;
        $model->dateUpdated = DateTimeHelper::toDateTime($r->dateUpdated) ?: null;
        $model->uid = $r->uid;

        return $model;
    }

    /**
     * Progress text for lists.
     */
    public function getProgressLabel(): string
    {
        if ($this->total !== null) {
            return Craft::t('mailcraft', '{processed} of {total}', [
                'processed' => $this->processed,
                'total' => $this->total,
            ]);
        }

        return Craft::t('mailcraft', ucfirst($this->status));
    }

    /**
     * Whether this send is still waiting in the queue and can be cancelled.
     */
    public function isCancellable(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    /**
     * Returns the user who started the send, or null when deleted.
     */
    public function getSender(): ?User
    {
        return $this->senderId ? User::find()->id($this->senderId)->status(null)->one() : null;
    }

    /**
     * Returns the Status page URL filtered to this send.
     */
    public function getStatusUrl(): string
    {
        return UrlHelper::cpUrl('mailcraft/status', ['send' => $this->id]);
    }

    /**
     * Returns the queue manager URL of the pending job, if there is one.
     */
    public function getQueueUrl(): ?string
    {
        if ($this->status !== self::STATUS_QUEUED || !$this->jobId) {
            return null;
        }

        $queue = Craft::$app->getQueue();
        if (!$queue instanceof Queue) {
            return null;
        }

        try {
            $queue->getJobDetails($this->jobId);
        } catch (\Throwable) {
            return null;
        }

        return UrlHelper::cpUrl('utilities/queue-manager/' . $this->jobId);
    }
}
