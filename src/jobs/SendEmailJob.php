<?php

namespace frontendservices\mailcraft\jobs;

use Craft;
use craft\queue\BaseJob;
use frontendservices\mailcraft\MailCraft;
use RuntimeException;
use yii\queue\RetryableJobInterface;

class SendEmailJob extends BaseJob implements RetryableJobInterface
{
    public array $variables;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $emailService = MailCraft::getInstance()->emailService;

        if (!$emailService->sendEmail($this->variables)) {
            $reason = $emailService->getLastError();

            throw new RuntimeException(sprintf(
                'MailCraft could not send "%s" to %s%s',
                $this->variables['subject'] ?? '',
                $this->variables['to'] ?? '',
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
        return Craft::t('mailcraft', 'Sending email');
    }
}
