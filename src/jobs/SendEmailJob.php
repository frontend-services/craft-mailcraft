<?php

namespace frontendservices\mailcraft\jobs;

use Craft;
use craft\queue\BaseJob;
use frontendservices\mailcraft\MailCraft;

class SendEmailJob extends BaseJob
{
    public array $variables;

    public function execute($queue): void
    {
        $this->setProgress($queue, 0.5);

        MailCraft::getInstance()->emailService->sendEmail($this->variables);

        $this->setProgress($queue, 1);
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return Craft::t('mailcraft', 'Sending email');
    }
}