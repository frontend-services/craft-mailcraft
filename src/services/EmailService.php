<?php

namespace frontendservices\mailcraft\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\ModelEvent;
use craft\mail\Message;
use frontendservices\mailcraft\elements\EmailTemplate;
use frontendservices\mailcraft\events\TriggerEvents;
use frontendservices\mailcraft\jobs\SendEmailJob;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use yii\base\Event;
use yii\base\Exception;
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
     */
    public function init(): void
    {
        parent::init();

        // Attach event handlers if we're not in console
        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->attachEventHandlers();
        }
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
     * Handle all event triggers
     */
    private function attachEventHandlers(): void
    {
        // User events (Standard)
        Event::on(
            User::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                if ($event->isNew) {
                    $this->handleTrigger(TriggerEvents::EVENT_USER_CREATE, ['user' => $event->sender]);
                } else {
                    $this->handleTrigger(TriggerEvents::EVENT_USER_UPDATE, ['user' => $event->sender]);
                }
            }
        );

        // Entry events
        Event::on(
            Entry::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                /** @var Entry $entry */
                $entry = $event->sender;
                // TriggerEvents::EVENT_ENTRY_CREATE
                if (
                    ($entry->enabled && $entry->getEnabledForSite()) &&
                    $entry->firstSave &&
                    !$entry->propagating
                ) {
                    $this->handleTrigger(TriggerEvents::EVENT_ENTRY_CREATE, ['entry' => $entry]);
                // TriggerEvents::EVENT_ENTRY_UPDATE
                } elseif (
                    !($entry->getIsDraft()) &&
                    !($entry->duplicateOf && $entry->getIsCanonical() && !$entry->updatingFromDerivative) &&
                    ($entry->enabled && $entry->getEnabledForSite()) &&
                    !$entry->firstSave &&
                    !$entry->propagating &&
                    !$entry->isProvisionalDraft &&
                    !$entry->resaving &&
                    !($entry->getIsRevision())
                ) {
                    $this->handleTrigger(TriggerEvents::EVENT_ENTRY_UPDATE, ['entry' => $entry]);
                }
            }
        );

        // Entry deletion
        Event::on(
            Entry::class,
            Element::EVENT_BEFORE_DELETE,
            function(Event $event) {
                $this->handleTrigger(TriggerEvents::EVENT_ENTRY_DELETE, ['entry' => $event->sender]);
            }
        );

        // User email verification
        Event::on(
            User::class,
            Model::EVENT_AFTER_VALIDATE,
            function(Event $event) {
                $this->handleTrigger(TriggerEvents::EVENT_USER_VERIFY, ['user' => $event->sender]);
            }
        );

        // Commerce events (if plugin is installed)
        if (Craft::$app->plugins->isPluginEnabled('commerce')) {
            Event::on(
                \craft\commerce\elements\Order::class,
                \craft\commerce\elements\Order::EVENT_AFTER_COMPLETE_ORDER,
                function(Event $event) {
                    $this->handleTrigger(TriggerEvents::EVENT_COMMERCE_ORDER_COMPLETE, ['order' => $event->sender]);
                }
            );

            Event::on(
                \craft\commerce\elements\Order::class,
                \craft\commerce\elements\Order::EVENT_AFTER_ORDER_STATUS_CHANGE,
                function(\craft\commerce\events\OrderStatusEvent $event) {
                    $this->handleTrigger(
                        TriggerEvents::EVENT_COMMERCE_ORDER_STATUS,
                        [
                            'order' => $event->order,
                            'oldStatus' => $event->oldStatus,
                            'newStatus' => $event->newStatus,
                        ]
                    );
                }
            );
        }
    }

    /**
     * Handle a trigger event
     */
    private function handleTrigger(string $eventName, array $variables = []): void
    {
        // Find all email templates for this event
        $templates = EmailTemplate::find()
            ->event($eventName)
            ->status(['enabled'])
            ->all();

        foreach ($templates as $template) {
            if (!$this->testTemplateConditions($template, $variables)) {
                continue;
            }

            $this->queueEmail($template, $variables, $template->delay ?: self::QUEUE_DELAY);
        }
    }

    /**
     * Test template conditions
     */
    private function testTemplateConditions(EmailTemplate $template, mixed $variables = []): bool
    {
        if ($template->conditions) {
            try {
                return (bool)Craft::$app->view->renderObjectTemplate($template->conditions, $variables);
            } catch (Exception|\Throwable $e) {
                Craft::error('Error with conditions: ' . $e->getMessage(), __METHOD__);
                return false;
            }
        }

        return true;
    }
}