<?php

namespace frontendservices\mailcraft\jobs;

use Craft;
use craft\queue\BaseJob;
use frontendservices\mailcraft\MailCraft;
use RuntimeException;
use yii\queue\RetryableJobInterface;

/**
 * Performs one delivery through one channel.
 *
 * The class name is kept from before channels existed because serialised jobs reference it.
 * Such jobs carry the email payload in `variables` and no `channel`.
 */
class SendEmailJob extends BaseJob implements RetryableJobInterface
{
    /**
     * @var string|null Channel handle. Null for jobs queued by MailCraft pre 1.3.x
     */
    public ?string $channel = null;

    /**
     * @var array The delivery payload built by the channel
     */
    public array $payload = [];

    /**
     * @var array Legacy (1.3.x) email payload. Only read when $channel is null
     */
    public array $variables = [];

    /**
     * @var int|null Status row to update, or null for jobs queued by 1.4.0 and earlier
     */
    public ?int $statusId = null;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $registry = MailCraft::getInstance()->channelRegistry;

        if ($this->channel === null && $this->variables !== []) {
            $channel = $registry->getChannel('email');
            $payload = $this->variables;
        } else {
            $channel = $registry->getChannel((string)$this->channel);
            $payload = $this->payload;
        }

        $statusService = MailCraft::getInstance()->statusService;

        if ($channel === null) {
            $message = sprintf(
                'MailCraft: channel "%s" is not registered; is the plugin that provides it installed?',
                (string)$this->channel
            );
            $statusService->markFailed($this->statusId, $message);

            throw new RuntimeException($message);
        }

        if (!$channel->deliver($payload)) {
            $reason = $channel->getLastError();
            $message = sprintf(
                'MailCraft could not send "%s" via %s to %s%s',
                $payload['subject'] ?? '',
                $channel->getName(),
                $channel->describeRecipient($payload),
                $reason ? ': ' . $reason : '. See the Craft log for details.'
            );
            $statusService->markFailed($this->statusId, $message);

            throw new RuntimeException($message);
        }

        $statusService->markSent($this->statusId);
    }

    /**
     * @inheritdoc
     */
    public function getTtr(): int
    {
        // A single send never needs long; a short TTR also means a failed
        // attempt is retried after about a minute instead of five.
        return 60;
    }

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        // Allow two automatic retries for transient transport failures.
        $retry = $attempt < 3;
        if ($retry) {
            MailCraft::getInstance()->statusService->markQueued($this->statusId, null, $error instanceof \Throwable ? $error->getMessage() : null);
        } else {
            // execute() may not have marked the row (a throwing deliver(), or a TTR expiry
            // where the job never ran), so mark it here when giving up
            MailCraft::getInstance()->statusService->markFailed(
                $this->statusId,
                $error instanceof \Throwable ? $error->getMessage() : 'Gave up after ' . $attempt . ' attempts'
            );
        }

        return $retry;
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return Craft::t('mailcraft', 'Sending message');
    }
}
