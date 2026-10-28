<?php

namespace frontendservices\mailcraft\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use frontendservices\mailcraft\base\AbstractEventProvider;
use frontendservices\mailcraft\elements\EmailTemplate;
use frontendservices\mailcraft\helpers\TemplateHelper;
use frontendservices\mailcraft\jobs\SendEmailJob;
use frontendservices\mailcraft\MailCraft;
use frontendservices\mailcraft\services\StatusService;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use yii\base\InvalidConfigException;

/**
 * Email Service
 */
class EmailService extends Component
{
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
     * Send an email using a template.
     *
     * Kept for backward compatibility; delivery now lives in {@see \frontendservices\mailcraft\channels\EmailChannel::deliver()}.
     */
    public function sendEmail(array $variables = []): bool
    {
        $this->lastError = null;

        $channel = MailCraft::getInstance()->channelRegistry->getChannel('email');
        if ($channel === null) {
            $this->lastError = 'The email channel is not registered.';
            Craft::error('Error sending email: ' . $this->lastError, __METHOD__);
            return false;
        }

        $result = $channel->deliver($variables);
        $this->lastError = $channel->getLastError();

        return $result;
    }

    /**
     * Returns the reason the most recent sendEmail() call failed, if known.
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Renders every address and text field of a template against the given variables.
     *
     * Returns the array shape sendEmail() expects. `to` and `toName` are rendered but
     * not split into individual recipients; EmailChannel::buildDeliveries() does that.
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
     * Sends a test through every channel enabled on the template that supports test sending.
     *
     * @return array{sent: string[], errors: string[]} Channel names that sent, and "<name>: <reason>" for failures
     */
    public function sendTestToUser(EmailTemplate $template, User $user): array
    {
        $result = ['sent' => [], 'errors' => []];

        foreach ($template->getEnabledChannels() as $channel) {
            if (!$channel->supportsTestSend()) {
                continue;
            }

            if ($channel->sendTest($template, $user)) {
                $result['sent'][] = $channel->getName();
            } else {
                $result['errors'][] = $channel->getName() . ': ' . ($channel->getLastError() ?? 'unknown error');
            }
        }

        return $result;
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
            // Craft Twig globals, don't shadow them
            if (in_array($key, ['now', 'siteName', 'siteUrl', 'currentSite'], true)) {
                continue;
            }
            $data[$key] = $this->sampleValue((string)$key, is_array($definition) ? $definition : []);
        }

        return array_merge($data, TemplateHelper::getRuntimeVariables());
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

        if (array_key_exists('sample', $definition)) {
            return $definition['sample'];
        }

        if (!empty($definition['fields']) && is_array($definition['fields'])) {
            $sample = [];
            foreach ($definition['fields'] as $field => $fieldDefinition) {
                $target = &$sample;
                foreach (explode('.', (string)$field) as $part) {
                    if (!isset($target[$part]) || !is_array($target[$part])) {
                        $target[$part] = [];
                    }
                    $target = &$target[$part];
                }
                $target = $this->sampleFieldValue((string)$field, $fieldDefinition);
                unset($target);
            }
            return $sample;
        }

        return match ($key) {
            'newStatus' => 'shipped',
            'oldStatus' => 'processing',
            'newStatusName' => 'Shipped',
            'oldStatusName' => 'Processing',
            'attempt' => 1,
            'level' => 'newUpdates',
            'error' => 'Example error message',
            'changedFields' => ['Title'],
            'changedFieldsList' => 'Title',
            default => $definition['description'] ?? ('Example ' . $key),
        };
    }

    /**
     * Sample value for one declared field when no real element is available:
     * dates for date-like fields, booleans for flags, numbers for totals, the label otherwise.
     */
    private function sampleFieldValue(string $field, mixed $definition): mixed
    {
        $label = TemplateHelper::fieldLabel($definition, $field);
        $example = is_array($definition) ? (string)($definition['example'] ?? '') : '';
        $leaf = substr($field, strrpos($field, '.') !== false ? strrpos($field, '.') + 1 : 0);

        if (preg_match('/date/i', $leaf) || str_contains($example, '|date')) {
            return new \DateTime();
        }
        if (preg_match('/^(is|has)[A-Z]/', $leaf) || in_array($leaf, ['admin', 'enabled'], true)) {
            return true;
        }
        if (preg_match('/^(total|count|width|height|attempt|line)/i', $leaf) || preg_match('/(Total|Price|Cost|Tax|Discount|Paid)$/', $leaf)) {
            return 1;
        }
        if (str_contains($example, '|filesize')) {
            return 1024;
        }

        return $label;
    }

    /**
     * Renders a template once and hands it to every channel that is enabled on the template
     * and allowed in the plugin settings, one queue job per delivery payload.
     *
     * @param int $delay Seconds to wait before sending; 0 sends as soon as the queue runs
     */
    public function dispatch(EmailTemplate $template, array $variables = [], int $delay = 0): void
    {
        $content = [
            'subject' => (string)$this->renderString($template->subject, $variables),
            'body' => (string)$this->renderString($template->template, $variables),
        ];

        $channels = $template->getEnabledChannels();

        $wanted = array_keys(array_filter($template->channels, static fn($c) => !empty($c['enabled'])));
        foreach (array_diff($wanted, array_keys($channels)) as $handle) {
            Craft::warning(sprintf(
                'MailCraft: channel "%s" is enabled on template "%s" (#%s) but not allowed in the plugin settings; skipped.',
                $handle,
                $template->title,
                $template->id
            ), __METHOD__);
        }

        if ($channels === []) {
            Craft::warning(sprintf(
                'MailCraft: template "%s" (#%s) has no enabled channel; nothing sent.',
                $template->title,
                $template->id
            ), __METHOD__);
            return;
        }

        $sendDirectly = $delay <= 0 && MailCraft::getInstance()->getSettings()->sendInstantEmailsDirectly;
        $sendTime = time() + $delay;
        $statusService = MailCraft::getInstance()->statusService;
        $dueAt = (new \DateTime())->setTimestamp($sendTime);

        foreach ($channels as $channel) {
            foreach ($channel->buildDeliveries($template, $content, $variables) as $payload) {
                $statusId = $statusService->record($channel, $template, $content, $payload, StatusService::STATUS_QUEUED, null, $dueAt);

                if ($sendDirectly) {
                    if ($channel->deliver($payload)) {
                        $statusService->markSent($statusId);
                        continue;
                    }

                    $directError = $channel->getLastError() ?? 'unknown error';
                    $statusService->markFailed($statusId, $directError);

                    Craft::warning(sprintf(
                        'Direct send of "%s" via %s to %s failed (%s); queueing it instead.',
                        $content['subject'],
                        $channel->getName(),
                        $payload['to'] ?? '',
                        $channel->getLastError() ?? 'unknown error'
                    ), __METHOD__);

                    // back to queued before the push, keeping the direct-send error, so a
                    // fast worker's result is never overwritten afterwards
                    $statusService->markQueued($statusId, null, $directError);
                }

                $jobId = Craft::$app->queue->delay($delay)->push(new SendEmailJob([
                    'channel' => $channel->getHandle(),
                    'payload' => $payload,
                    'statusId' => $statusId,
                    'description' => 'MailCraft Scheduler - ' . date('Y-m-d H:i:s', $sendTime) . ' - ' . $content['subject'],
                ]));
                $statusService->setJobId($statusId, $jobId !== null ? (string)$jobId : null);
            }
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

        $variables += TemplateHelper::getRuntimeVariables();

        // Each template is isolated: a failure in one must not stop the others from sending.
        foreach ($templates as $template) {
            try {
                if (!$provider->testConditions($template, $variables)) {
                    continue;
                }

                $this->dispatch($template, $variables, $template->delay ?: 0);
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