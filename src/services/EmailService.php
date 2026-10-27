<?php

namespace frontendservices\mailcraft\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
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
     * Renders every address and text field of a template against the given variables.
     *
     * Returns the array shape sendEmail() expects. `to` and `toName` are rendered but
     * not split into individual recipients; queueEmail() does that.
     */
    public function buildEmailVariables(EmailTemplate $template, array $variables = []): array
    {
        return [
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
    }

    /**
     * Sends a template immediately to a single address, rendered with sample data.
     *
     * Used by the "Send a test to me" button on the edit page. The template does not have to be saved.
     */
    public function sendTest(EmailTemplate $template, string $to, ?string $toName = null): bool
    {
        $variables = $this->buildEmailVariables($template, $this->getSampleData($template->event));
        $variables['to'] = $to;
        $variables['toName'] = $toName;
        $variables['subject'] = '[' . Craft::t('mailcraft', 'Test') . '] ' . ($variables['subject'] ?? '');

        return $this->sendEmail($variables);
    }

    /**
     * Renders a template string with the given variables, returning null if the string is empty.
     *
     * @throws \Throwable
     */
    public function renderTemplateString(?string $string, array $variables = []): ?string
    {
        if (!$string) {
            return null;
        }

        // Twig escapes `>` and `<` to `&gt;` and `&lt;`, which breaks HTML in email templates. Unescape them before rendering.
        $string = preg_replace('/({[{%][^}]*?)&gt;([^}]*?[%}])/', '$1>$2', $string);
        $string = preg_replace('/({[{%][^}]*?)&lt;([^}]*?[%}])/', '$1<$2', $string);

        return Craft::$app->view->renderString($string, $variables);
    }

    /**
     * Builds realistic sample variables for an event so a template can be previewed without the event actually happening.
     *
     * Element-typed variables (user, entry, asset, order) use a real element from the site when one exists. Everything else falls 
     * back to the provider's documented field list so `{{ user.email }}` still renders something readable.
     */
    public function getSampleData(?string $event): array
    {
        if (!$event) {
            return [];
        }

        $provider = MailCraft::getInstance()->eventRegistry->getProviders()[$event] ?? null;
        if (!$provider) {
            return [];
        }

        $data = [];
        foreach ($provider->getTemplateVariables() as $key => $definition) {
            // `now`, `siteName` and `siteUrl` are Twig globals in Craft, don't shadow them
            if (in_array($key, ['now', 'siteName', 'siteUrl'], true)) {
                continue;
            }
            $data[$key] = $this->sampleValue((string)$key, is_array($definition) ? $definition : []);
        }

        return $data;
    }

    private function sampleValue(string $key, array $definition): mixed
    {
        $type = strtolower((string)($definition['type'] ?? ''));

        try {
            $element = match ($type) {
                'user' => Craft::$app->getUser()->getIdentity() ?? User::find()->status(null)->one(),
                'entry' => Entry::find()->status(null)->orderBy(['dateUpdated' => SORT_DESC])->one(),
                'asset' => Asset::find()->orderBy(['dateUpdated' => SORT_DESC])->one(),
                'order' => class_exists(\craft\commerce\elements\Order::class)
                    ? \craft\commerce\elements\Order::find()->isCompleted()->orderBy(['dateOrdered' => SORT_DESC])->one()
                    : null,
                default => null,
            };
        } catch (\Throwable $e) {
            Craft::warning('Could not load a sample element for ' . $key . ': ' . $e->getMessage(), __METHOD__);
            $element = null;
        }

        if ($element !== null) {
            return $element;
        }

        if (!empty($definition['fields']) && is_array($definition['fields'])) {
            $sample = [];
            foreach ($definition['fields'] as $field => $label) {
                $sample[$field] = is_string($label) ? $label : (string)$field;
            }
            return $sample;
        }

        return match ($key) {
            'newStatus' => 'shipped',
            'oldStatus' => 'processing',
            'attempt' => 1,
            'error' => 'Example error message',
            'changedFields' => ['Title'],
            'changedFieldsList' => 'Title',
            default => $definition['description'] ?? ('Example ' . $key),
        };
    }

    /**
     * Queue an email for sending
     */
    private function queueEmail(EmailTemplate $template, array $variables = [], int $delay = self::QUEUE_DELAY): void
    {
        $emailVariables = $this->buildEmailVariables($template, $variables);
        $renderedTo = $emailVariables['to'];
        $renderedToName = $emailVariables['toName'];
        unset($emailVariables['to'], $emailVariables['toName']);

        $sendTime = time() + $delay;

        $emails = array_map(
            'trim',
            explode(',', (string)$renderedTo),
        );

        $names = [];
        if (count($emails) > 1) {
            $names = explode(',', (string)$renderedToName);
        } else {
            $names[] = $renderedToName;
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
            return $this->renderTemplateString($string, $variables);
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