<?php

namespace frontendservices\mailcraft\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\ModelEvent;
use craft\mail\Message;
use frontendservices\mailcraft\base\AbstractEventProvider;
use frontendservices\mailcraft\elements\EmailTemplate;
use frontendservices\mailcraft\events\TriggerEvents;
use frontendservices\mailcraft\jobs\SendEmailJob;
use frontendservices\mailcraft\MailCraft;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use yii\base\Event;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\Model;

/**
 * Email Service
 */
class EmailService extends Component
{
    /**
     * Queue job delay (in seconds)
     */
    private const QUEUE_DELAY = 0;

    /**
     * Initialize email service
     *
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        parent::init();

        // Attach event handlers if we're not in console
//        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
//            $this->attachEventHandlers();
//        }

        $this->registerEventHandlers();
    }

    /**
     * Send an email using a template
     */
    public function sendEmail(array $variables = []): bool
    {
        try {
            $message = new Message();

            // Set basic email properties
            $message->setCharset('utf-8');
            $message->setSubject($variables['subject']);
            if ($variables['toName']) {
                $message->setTo([$variables['to'] => $variables['toName']]);
            } else {
                $message->setTo($variables['to']);
            }
            $message->setCc($variables['cc']);
            $message->setBcc($variables['bcc']);
            if ($variables['from']) {
                if ($variables['fromName']) {
                    $message->setFrom([$variables['from'] => $variables['fromName']]);
                } else {
                    $message->setFrom($variables['from']);
                }
            }
            $message->setReplyTo($variables['replyTo']);
            $message->setHtmlBody($variables['message']);

            return Craft::$app->mailer->send($message);
        } catch (\Throwable $e) {
            Craft::error('Error sending email: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Queue an email for sending
     */
    private function queueEmail(EmailTemplate $template, array $variables = [], int $delay = self::QUEUE_DELAY): void
    {
        $emailVariables = [
            'subject' => $this->renderString($template->subject, $variables),
            'to' => $this->renderString($template->to, $variables),
            'toName' => $this->renderString($template->toName, $variables),
            'cc' => $this->renderString($template->cc, $variables),
            'bcc' => $this->renderString($template->bcc, $variables),
            'from' => $this->renderString($template->from, $variables),
            'fromName' => $this->renderString($template->fromName, $variables),
            'replyTo' => $this->renderString($template->replyTo, $variables),
            'message' => $this->renderString($template->template, $variables),
        ];

        $sendTime = time() + $delay;
        Craft::$app->queue->delay($delay)->push(new SendEmailJob([
            'variables' => $emailVariables,
            'description' => 'MailCraft Scheduler - ' . date('Y-m-d H:i:s', $sendTime) . ' - ' . $emailVariables['subject'],
        ]));
    }

    /**
     * Register event handlers
     *
     * @throws InvalidConfigException
     */
    private function registerEventHandlers(): void
    {
        $registry = MailCraft::getInstance()->eventRegistry;

        foreach ($registry->getProviders() as $provider) {
            $provider->registerEventListener(function(array $variables) use ($provider) {
                $this->handleTrigger($provider, $variables);
            });
        }
    }

    /**
     * Render a string with variables
     */
    private function renderString(?string $string, array $variables = []): ?string
    {
        try {
            if (!$string) {
                return null;
            }

            return Craft::$app->view->renderString($string, $variables);
        } catch (LoaderError|SyntaxError $e) {
            Craft::error('Error rendering email template: ' . $e->getMessage(), __METHOD__);
        }

        return '';
    }


    /**
     * Handle a trigger event
     */
    private function handleTrigger(AbstractEventProvider $provider, array $variables = []): void
    {
        // Find all email templates for this event
        $templates = EmailTemplate::find()
            ->event($provider->getEventId())
            ->status(['enabled'])
            ->all();

        foreach ($templates as $template) {
            if (!$provider->testConditions($template, $variables)) {
                continue;
            }

            $this->queueEmail($template, $variables, $template->delay ?: self::QUEUE_DELAY);
        }
    }

    /**
     * Test template conditions
     */
//    private function testTemplateConditions(EmailTemplate $template, mixed $variables = []): bool
//    {
//        if (trim($template->conditions)) {
//            try {
//                return (bool)Craft::$app->view->renderObjectTemplate($template->conditions, $variables);
//            } catch (Exception|\Throwable $e) {
//                Craft::error('Error with conditions: ' . $e->getMessage(), __METHOD__);
//                return false;
//            }
//        }
//
//        return true;
//    }
}