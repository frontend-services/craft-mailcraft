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

        if ($channel === null) {
            throw new RuntimeException(sprintf(
                'MailCraft: channel "%s" is not registered; is the plugin that provides it installed?',
                (string)$this->channel
            ));
        }

        if (!$channel->deliver($payload)) {
            $reason = $channel->getLastError();

            throw new RuntimeException(sprintf(
                'MailCraft could not send "%s" via %s to %s%s',
                $payload['subject'] ?? '',
                $channel->getName(),
                $payload['to'] ?? '',
                $reason ? ': ' . $reason : '. See the Craft log for details.'
            ));
        }
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
        return $attempt < 3;
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return Craft::t('mailcraft', 'Sending message');
    }
}
