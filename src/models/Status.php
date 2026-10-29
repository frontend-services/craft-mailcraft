<?php

namespace frontendservices\mailcraft\models;

use Craft;
use craft\base\Model;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\queue\Queue;
use DateTime;
use frontendservices\mailcraft\base\AbstractChannel;
use frontendservices\mailcraft\elements\EmailTemplate;
use frontendservices\mailcraft\MailCraft;
use frontendservices\mailcraft\records\StatusRecord;
use frontendservices\mailcraft\services\StatusService;

/**
 * A logged delivery.
 */
class Status extends Model
{
    public ?int $id = null;
    public ?int $templateId = null;
    public ?string $templateTitle = null;
    public string $channel = '';
    public string $status = StatusService::STATUS_QUEUED;
    public ?string $subject = null;
    public ?string $recipient = null;

    /**
     * @var string JSON of the channel payload
     */
    public string $payload = '[]';
    public ?string $error = null;
    public int $attempts = 0;
    public ?string $jobId = null;
    public ?int $resentFromId = null;
    /**
     * When the delivery is due (queued, failed, cancelled) or was made (sent).
     */
    public ?DateTime $dateSent = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    private ?EmailTemplate $_template = null;
    private bool $_templateLoaded = false;

    /**
     * Builds a model from its record.
     */
    public static function fromRecord(StatusRecord $record): self
    {
        $model = new self();
        $model->id = $record->id !== null ? (int)$record->id : null;
        $model->templateId = $record->templateId !== null ? (int)$record->templateId : null;
        $model->templateTitle = $record->templateTitle;
        $model->channel = (string)$record->channel;
        $model->status = (string)$record->status;
        $model->subject = $record->subject;
        $model->recipient = $record->recipient;
        $model->payload = (string)$record->payload;
        $model->error = $record->error;
        $model->attempts = (int)$record->attempts;
        $model->jobId = $record->jobId;
        $model->resentFromId = $record->resentFromId !== null ? (int)$record->resentFromId : null;
        $model->dateSent = DateTimeHelper::toDateTime($record->dateSent) ?: null;
        $model->dateCreated = DateTimeHelper::toDateTime($record->dateCreated) ?: null;
        $model->dateUpdated = DateTimeHelper::toDateTime($record->dateUpdated) ?: null;
        $model->uid = $record->uid;

        return $model;
    }

    /**
     * Get the cancelling reason to show on Status page
     */
    public function getCancelDetail(): ?string
    {
        if ($this->status !== StatusService::STATUS_CANCELLED || !$this->error) {
            return null;
        }

        $label = Craft::t('mailcraft', 'Cancelled');
        $reason = trim($this->error);
        if ($reason === $label) {
            return null;
        }
        if (str_starts_with($reason, $label . ' ')) {
            return trim(substr($reason, strlen($label)));
        }

        return $reason;
    }

    /**
     * Returns the decoded payload.
     */
    public function getPayload(): array
    {
        $payload = Json::decodeIfJson($this->payload);

        return is_array($payload) ? $payload : [];
    }

    /**
     * Returns the template this delivery came from, or null when it has been deleted.
     */
    public function getTemplate(): ?EmailTemplate
    {
        if (!$this->_templateLoaded) {
            $this->_templateLoaded = true;
            if ($this->templateId) {
                /** @var EmailTemplate|null $template */
                $template = EmailTemplate::find()->id($this->templateId)->status(null)->one();
                $this->_template = $template;
            }
        }

        return $this->_template;
    }

    /**
     * Returns the live template title, falling back to the title saved with the log entry.
     */
    public function getTemplateLabel(): string
    {
        $template = $this->getTemplate();
        if ($template !== null) {
            return (string)$template->title;
        }

        return $this->templateTitle ?? '';
    }

    /**
     * Returns the channel, or null when it is no longer registered.
     */
    public function getChannel(): ?AbstractChannel
    {
        return MailCraft::getInstance()->channelRegistry->getChannel($this->channel);
    }

    /**
     * Returns the channel name, or its handle when the channel is not registered.
     */
    public function getChannelName(): string
    {
        $channel = $this->getChannel();

        return $channel !== null ? $channel->getName() : $this->channel;
    }

    /**
     * Whether this delivery can be sent again.
     */
    public function isResendable(): bool
    {
        return $this->getChannel() !== null && $this->status !== StatusService::STATUS_QUEUED;
    }

    /**
     * Whether this delivery is still waiting in the queue and can be cancelled.
     */
    public function isCancellable(): bool
    {
        return $this->status === StatusService::STATUS_QUEUED;
    }

    /**
     * Returns the queue manager URL of the pending job, if there is one.
     */
    public function getQueueUrl(): ?string
    {
        if ($this->status !== StatusService::STATUS_QUEUED || !$this->jobId) {
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
