<?php

namespace frontendservices\mailcraft\channels;

use Craft;
use craft\elements\User;
use craft\helpers\Json;
use DateTime;
use frontendservices\mailcraft\base\AbstractChannel;
use frontendservices\mailcraft\elements\EmailTemplate;
use frontendservices\mailcraft\MailCraft;
use frontendservices\mailcraft\models\Message;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

/**
 * Writes a template into the recipients' private message inboxes (`mailcraft_messages`).
 *
 * Its per-template settings live in `channels['privateMessage']`: `to`, `from`, `type`,
 * `pinned`, `expiresDays`, `dateExpires`, `meta1`...`meta5` and `data`. The text settings are
 * admin-written Twig rendered against the event variables.
 */
class PrivateMessageChannel extends AbstractChannel
{
    public const HANDLE = 'privateMessage';

    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function getHandle(): string
    {
        return self::HANDLE;
    }

    public function getName(): string
    {
        return Craft::t('mailcraft', 'Private message');
    }

    /**
     * A message is a local database write, so delay-0 messages are written during the
     * triggering request.
     */
    public function deliversDirectly(): bool
    {
        return true;
    }

    public function getTemplateSettingsHtml(EmailTemplate $template): string
    {
        return Craft::$app->getView()->renderTemplate('mailcraft/channels/privateMessage/_template', [
            'emailTemplate' => $template,
        ]);
    }

    public function validateTemplateSettings(EmailTemplate $template): void
    {
        $settings = $template->getChannelSettings(self::HANDLE);

        if (trim((string)($settings['to'] ?? '')) === '') {
            $template->addError('channels.privateMessage.to', Craft::t('mailcraft', 'To is required.'));
        }

        $expiresDays = $settings['expiresDays'] ?? null;
        if ($expiresDays !== null && $expiresDays !== '' && !(is_int($expiresDays) ? $expiresDays >= 0 : ctype_digit(trim((string)$expiresDays)))) {
            $template->addError('channels.privateMessage.expiresDays', Craft::t('mailcraft', 'Must be a whole number of days.'));
        }
    }

    /**
     * One payload per resolved recipient. Recipients that do not resolve to a user are
     * skipped with a warning.
     */
    public function buildDeliveries(EmailTemplate $template, array $content, array $variables): array
    {
        $base = $this->buildBasePayload($template, $content, $variables);

        $renderedTo = (string)$this->render($this->setting($template, 'to'), $variables);
        if (trim($renderedTo) === '') {
            Craft::warning(sprintf(
                'MailCraft: private message channel on template "%s" rendered an empty recipient; nothing sent.',
                $template->title
            ), __METHOD__);
            return [];
        }

        $payloads = [];
        $seen = [];
        foreach (explode(',', $renderedTo) as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }

            $user = $this->resolveUser($value);
            if ($user === null) {
                Craft::warning(sprintf(
                    'MailCraft: private message recipient "%s" of template "%s" (#%s) is not a user; skipped.',
                    $value,
                    $template->title,
                    $template->id
                ), __METHOD__);
                continue;
            }

            if (isset($seen[$user->id])) {
                continue;
            }
            $seen[$user->id] = true;

            $payload = $base;
            $payload['toId'] = (int)$user->id;
            $payload['toEmail'] = (string)$user->email;
            $payloads[] = $payload;
        }

        return $payloads;
    }

    public function deliver(array $payload): bool
    {
        $this->lastError = null;

        try {
            $dateExpires = null;
            if (!empty($payload['dateExpires']) && is_string($payload['dateExpires'])) {
                $dateExpires = $this->parseDate($payload['dateExpires']);
                if ($dateExpires === null) {
                    Craft::warning(sprintf(
                        'MailCraft: private message expiry date "%s" is not a Y-m-d H:i:s date; ignored.',
                        $payload['dateExpires']
                    ), __METHOD__);
                }
            }
            if ($dateExpires === null && isset($payload['expiresDays']) && (int)$payload['expiresDays'] > 0) {
                $dateExpires = new DateTime('+' . (int)$payload['expiresDays'] . ' days');
            }

            $message = new Message();
            $message->toId = isset($payload['toId']) ? (int)$payload['toId'] : null;
            $message->fromId = isset($payload['fromId']) ? (int)$payload['fromId'] : null;
            $message->templateId = isset($payload['templateId']) ? (int)$payload['templateId'] : null;
            $message->siteId = isset($payload['siteId']) ? (int)$payload['siteId'] : null;
            $message->type = isset($payload['type']) && $payload['type'] !== '' ? (string)$payload['type'] : null;
            $message->subject = (string)($payload['subject'] ?? '');
            $message->content = (string)($payload['content'] ?? '');
            $message->pinned = !empty($payload['pinned']);
            $message->dateExpires = $dateExpires;
            for ($i = 1; $i <= 5; $i++) {
                $key = 'meta' . $i;
                $message->$key = isset($payload[$key]) && $payload[$key] !== '' ? (string)$payload[$key] : null;
            }
            $message->data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

            if (!$message->toId) {
                $this->lastError = 'The message has no recipient.';
                return false;
            }

            $service = MailCraft::getInstance()->privateMessages;
            if ($service->save($message)) {
                return true;
            }

            $this->lastError = $service->getLastError() ?? Craft::t('mailcraft', 'The message could not be saved.');
            Craft::error('MailCraft: error saving private message: ' . $this->lastError, __METHOD__);

            return false;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            Craft::error('MailCraft: error saving private message: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    public function supportsTestSend(): bool
    {
        return true;
    }

    public function sendTest(EmailTemplate $template, User $user): bool
    {
        $emailService = MailCraft::getInstance()->emailService;

        try {
            $content = $emailService->renderContentFor($template, $this->getHandle(), $emailService->getSampleData($template->event));
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            Craft::error('Error rendering private message test: ' . $e->getMessage(), __METHOD__);
            return false;
        }

        return $this->sendTestWithContent($template, $user, $content);
    }

    public function sendTestWithContent(EmailTemplate $template, User $user, array $content): bool
    {
        $this->lastError = null;

        try {
            $variables = MailCraft::getInstance()->emailService->getSampleData($template->event);
            $payload = $this->buildBasePayload($template, $content, $variables);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            Craft::error('Error rendering private message test: ' . $e->getMessage(), __METHOD__);
            return false;
        }

        $payload['toId'] = (int)$user->id;
        $payload['toEmail'] = (string)$user->email;
        $payload['subject'] = '[' . Craft::t('mailcraft', 'Test') . '] ' . $payload['subject'];

        return $this->deliver($payload);
    }

    public function getTemplateSummary(EmailTemplate $template): array
    {
        $type = trim((string)$this->setting($template, 'type'));

        return [
            Craft::t('mailcraft', 'To') => (string)$this->setting($template, 'to'),
            Craft::t('mailcraft', 'Type') => $type !== '' ? $type : '—',
        ];
    }

    public function describeRecipient(array $payload): string
    {
        return sprintf('user #%d (%s)', (int)($payload['toId'] ?? 0), (string)($payload['toEmail'] ?? ''));
    }

    /**
     * Finds a user by id (digits only), username or email. Null when nothing matches.
     */
    public function resolveUser(string $value): ?User
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $users = Craft::$app->getUsers();

        return ctype_digit($value)
            ? $users->getUserById((int)$value)
            : $users->getUserByUsernameOrEmail($value);
    }

    /**
     * Everything in a payload except the recipient.
     */
    private function buildBasePayload(EmailTemplate $template, array $content, array $variables): array
    {
        $fromId = null;
        $renderedFrom = trim((string)$this->render($this->setting($template, 'from'), $variables));
        if ($renderedFrom !== '') {
            $from = $this->resolveUser($renderedFrom);
            if ($from !== null) {
                $fromId = (int)$from->id;
            } else {
                Craft::warning(sprintf(
                    'MailCraft: private message sender "%s" of template "%s" (#%s) is not a user; sent as a system message.',
                    $renderedFrom,
                    $template->title,
                    $template->id
                ), __METHOD__);
            }
        }

        // a blank Type field falls back to the `type` variable, so a configured template keeps the
        // type passed by PrivateMessaging::send() and the frontend action (frontendSendingType)
        $type = trim((string)$this->render($this->setting($template, 'type'), $variables));
        if ($type === '' && is_scalar($variables['type'] ?? null)) {
            $type = trim((string)$variables['type']);
        }

        $expiresDays = $this->setting($template, 'expiresDays');
        $expiresDays = is_numeric($expiresDays) && (int)$expiresDays > 0 ? (int)$expiresDays : null;

        $dateExpires = trim((string)$this->render($this->setting($template, 'dateExpires'), $variables));
        if ($dateExpires !== '') {
            if ($this->parseDate($dateExpires) === null) {
                Craft::warning(sprintf(
                    'MailCraft: "Expires at" of template "%s" (#%s) did not render to a Y-m-d H:i:s date; ignored.',
                    $template->title,
                    $template->id
                ), __METHOD__);
                $dateExpires = '';
            }
        }

        $payload = [
            'toId' => null,
            'toEmail' => '',
            'fromId' => $fromId,
            'subject' => (string)($content['subject'] ?? ''),
            'content' => (string)($content['body'] ?? ''),
            'type' => $type !== '' ? mb_substr($type, 0, 100) : null,
            'pinned' => filter_var($this->setting($template, 'pinned') ?? false, FILTER_VALIDATE_BOOLEAN),
            'expiresDays' => $expiresDays,
            'dateExpires' => $dateExpires !== '' ? $dateExpires : null,
        ];

        for ($i = 1; $i <= 5; $i++) {
            $meta = (string)$this->render($this->setting($template, 'meta' . $i), $variables);
            $payload['meta' . $i] = $meta !== '' ? mb_substr($meta, 0, 255) : null;
        }

        $payload['data'] = null;
        $renderedData = trim((string)$this->render($this->setting($template, 'data'), $variables));
        if ($renderedData !== '') {
            $decoded = Json::decodeIfJson($renderedData);
            if (is_array($decoded)) {
                $payload['data'] = $decoded;
            } else {
                Craft::warning(sprintf(
                    'MailCraft: data of template "%s" (#%s) did not render to JSON; ignored.',
                    $template->title,
                    $template->id
                ), __METHOD__);
            }
        }

        $payload['siteId'] = $this->currentSiteId();
        $payload['templateId'] = $template->id ? (int)$template->id : null;

        return $payload;
    }

    /**
     * One per-template setting, or null when it is not set.
     */
    private function setting(EmailTemplate $template, string $key): mixed
    {
        return $template->getChannelSettings(self::HANDLE)[$key] ?? null;
    }

    /**
     * The id of the site being rendered, or null when there is no current site.
     */
    private function currentSiteId(): ?int
    {
        try {
            $site = Craft::$app->getSites()->getCurrentSite();

            return $site->id ? (int)$site->id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parses a strict `Y-m-d H:i:s` date (the "Expires at" format). Null when the string is
     * not exactly such a date, e.g. "2026-02-30 00:00:00" or trailing text.
     */
    private function parseDate(string $value): ?DateTime
    {
        $parsed = DateTime::createFromFormat(self::DATE_FORMAT, $value);
        if ($parsed === false || $parsed->format(self::DATE_FORMAT) !== $value) {
            return null;
        }

        return $parsed;
    }

    /**
     * Renders an admin-written setting, returning '' when it fails to compile so one bad
     * field does not stop the delivery.
     */
    private function render(mixed $string, array $variables): ?string
    {
        if (!is_string($string) || $string === '') {
            return null;
        }

        try {
            return MailCraft::getInstance()->emailService->renderTemplateString($string, $variables);
        } catch (LoaderError|SyntaxError $e) {
            Craft::error('Error rendering private message setting: ' . $e->getMessage(), __METHOD__);
        }

        return '';
    }
}
