<?php

namespace frontendservices\mailcraft\services;

use Craft;
use craft\base\Component;
use craft\mail\Mailer;
use craft\mail\Message;
use frontendservices\mailcraft\base\AbstractEventProvider;
use frontendservices\mailcraft\elements\EmailTemplate;
use frontendservices\mailcraft\jobs\SendEmailJob;
use frontendservices\mailcraft\MailCraft;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use yii\base\InvalidConfigException;

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
     * The reason the most recent sendEmail() call failed, if known.
     */
    private ?string $lastError = null;

    /**
     * Initialize email service
     *
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        parent::init();

        // log to craft
        Craft::info('Email service initialized. Stack trace: ' . json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)), __METHOD__);
        $this->registerEventHandlers();
    }

    /**
     * Send an email using a template
     */
    public function sendEmail(array $variables = []): bool
    {
        $this->lastError = null;

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

            $logger = Craft::getLogger();
            $logIndex = count($logger->messages);

            if (Craft::$app->mailer->send($message)) {
                return true;
            }

            $this->lastError = $this->findMailerError($logger->messages, $logIndex)
                ?? 'The mailer returned false without an error message.';
            Craft::error('Error sending email: ' . $this->lastError, __METHOD__);

            return false;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            Craft::error('Error sending email: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Returns the reason the most recent sendEmail() call failed, if known.
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Finds the most recent message logged by Craft's mailer.
     *
     * @param array $messages Yii logger message buffer (`[text, level, category, time, traces, memory]`)
     * @param int $fromIndex Only look at messages logged from this index onwards
     */
    private function findMailerError(array $messages, int $fromIndex): ?string
    {
        // Keys can be strings when Yii profiling is on, so walk by position. If the buffer
        // shrank, the logger flushed during send(); scan everything that is left.
        $keys = array_keys($messages);
        $start = count($keys) >= $fromIndex ? $fromIndex : 0;

        for ($i = count($keys) - 1; $i >= $start; $i--) {
            $entry = $messages[$keys[$i]];
            $text = $entry[0] ?? null;
            $category = $entry[2] ?? null;

            if ($category === Mailer::class . '::send' && is_string($text)) {
                return preg_replace('/^Error sending email: /', '', $text);
            }
        }

        return null;
    }

    /**
     * Queue an email for sending
     */
    private function queueEmail(EmailTemplate $template, array $variables = [], int $delay = self::QUEUE_DELAY): void
    {
        $emailVariables = [
            'subject' => $this->renderString($template->subject, $variables),
            'cc' => $this->renderString($template->cc, $variables),
            'bcc' => $this->renderString($template->bcc, $variables),
            'from' => $this->renderString($template->from, $variables),
            'fromName' => $this->renderString($template->fromName, $variables),
            'replyTo' => $this->renderString($template->replyTo, $variables),
            'message' => $this->renderString($template->template, $variables),
        ];

        $sendTime = time() + $delay;

        $emails = array_map(
            'trim',
            explode(',', $this->renderString($template->to, $variables)),
        );

        $names = [];
        if (count($emails) > 1) {
            $names = explode(',', $this->renderString($template->toName, $variables));
        } else {
            $names[] = $this->renderString($template->toName, $variables);
        }

        foreach ($emails as $index => $email) {
            $emailVariables['to'] = $email;
            $emailVariables['toName'] = $names[$index] ?? null;

            Craft::$app->queue->delay($delay)->push(new SendEmailJob([
                'variables' => $emailVariables,
                'description' => 'MailCraft Scheduler - ' . date('Y-m-d H:i:s', $sendTime) . ' - ' . $emailVariables['subject'],
            ]));
        }

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

            // fix &gt; and &lt; to < and > inside of {% %} and {{ }}
            $string = preg_replace('/({[{%][^}]*?)&gt;([^}]*?[%}])/', '$1>$2', $string);
            $string = preg_replace('/({[{%][^}]*?)&lt;([^}]*?[%}])/', '$1<$2', $string);

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
        try {
            // Find all email templates for this event
            $templates = EmailTemplate::find()
                ->event($provider->getEventId())
                ->status(['enabled'])
                ->all();
        } catch (\Throwable $e) {
            Craft::error('Error loading email templates for event ' . $provider->getEventId() . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);
            return;
        }

        // Each template is isolated: a failure in one must not stop the others from sending.
        foreach ($templates as $template) {
            try {
                if (!$provider->testConditions($template, $variables)) {
                    continue;
                }

                $this->queueEmail($template, $variables, $template->delay ?: self::QUEUE_DELAY);
            } catch (\Throwable $e) {
                Craft::error(sprintf(
                    'Error handling email template "%s" (#%s) for event %s: %s%s%s',
                    $template->title,
                    $template->id,
                    $provider->getEventId(),
                    $e->getMessage(),
                    "\n",
                    $e->getTraceAsString()
                ), __METHOD__);
            }
        }
    }
}