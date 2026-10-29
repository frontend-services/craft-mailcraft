<?php

namespace frontendservices\mailcraft\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\HtmlPurifier;
use craft\helpers\Json;
use DateTime;
use frontendservices\mailcraft\channels\PrivateMessageChannel;
use frontendservices\mailcraft\db\MessageQuery;
use frontendservices\mailcraft\elements\EmailTemplate;
use frontendservices\mailcraft\MailCraft;
use frontendservices\mailcraft\models\Message;
use frontendservices\mailcraft\records\MessageRecord;
use RuntimeException;

/**
 * Reads and writes private messages (`mailcraft_messages`).
 */
class PrivateMessageService extends Component
{
    private ?string $_lastError = null;

    /**
     * Sends a private message to one or more users through `MailCraft::send()`, one send per recipient.
     *
     * Options: 
     * - `to` (required; User, id, or a list of those)
     * - `from` (User, id, null)
     * - `type`
     * - `pinned`
     * - `expires` (DateTime, days, null)
     * - `meta1`...`meta5`
     * - `data` (array)
     * - `template`
     * (id, handle or element; overrides the configured one), `delay` (seconds), `site` (Site, id, handle) and `variables` (extra Twig variables for template).
     *
     * @return bool True only when every recipient's send succeeded
     */
    public function send(string $subject, string $content, array $options = []): bool
    {
        $allowed = MailCraft::getInstance()->channelRegistry->getAllowedChannels();
        if (!isset($allowed[PrivateMessageChannel::HANDLE])) {
            Craft::warning('MailCraft: the private message channel is not allowed; enable it under Settings → MailCraft → Allowed channels.', __METHOD__);
            return false;
        }

        $to = $options['to'] ?? null;
        $recipients = is_array($to) ? array_values($to) : ($to === null ? [] : [$to]);
        if ($recipients === []) {
            Craft::warning('MailCraft: private message "' . $subject . '" has no recipient (`to`); nothing sent.', __METHOD__);
            return false;
        }

        $expires = $options['expires'] ?? null;
        if (!$expires instanceof \DateTimeInterface && !is_int($expires)) {
            $expires = null;
        }

        $from = $options['from'] ?? null;
        if (is_string($from) && ctype_digit($from)) {
            $from = (int)$from;
        }

        $variables = [
            'recipient' => null,
            'sender' => $from,
            'subject' => $subject,
            'content' => $content,
            'type' => $options['type'] ?? null,
            'pinned' => (bool)($options['pinned'] ?? false),
            'expires' => $expires,
        ];
        for ($i = 1; $i <= 5; $i++) {
            $variables['meta' . $i] = $options['meta' . $i] ?? null;
        }
        $variables['data'] = is_array($options['data'] ?? null) ? $options['data'] : [];
        $variables += is_array($options['variables'] ?? null) ? $options['variables'] : [];
        $variables['site'] = $options['site'] ?? null;

        $template = $options['template'] ?? null;
        $fromSetting = false;
        if ($template === null) {
            $template = MailCraft::getInstance()->getSettings()->privateMessageTemplate;
            $fromSetting = $template !== null;
        }
        if (is_string($template) && ctype_digit($template)) {
            $template = (int)$template;
        }
        if ($template === null) {
            $template = $this->buildBuiltInTemplate($options);
        }

        if (!$template instanceof EmailTemplate && !is_int($template) && !is_string($template)) {
            Craft::warning('MailCraft: the private message template option is not an id, handle or template; nothing sent.', __METHOD__);
            return false;
        }

        $resolved = MailCraft::getInstance()->emailService->resolveTemplate($template);
        if ($resolved === null || ($resolved->id && $resolved->enabled === false)) {
            if ($fromSetting) {
                Craft::warning(sprintf(
                    'MailCraft: the template "%s" configured in Settings → MailCraft → Private messages → Template is missing or disabled; no private message was sent.',
                    $template
                ), __METHOD__);
            } elseif ($resolved === null) {
                Craft::warning(sprintf('MailCraft: private message template "%s" not found; nothing sent.', $template), __METHOD__);
            } else {
                Craft::warning(sprintf('MailCraft: private message template "%s" (#%s) is disabled; nothing sent.', $resolved->title, $resolved->id), __METHOD__);
            }
            return false;
        }

        $delay = (int)($options['delay'] ?? 0);

        $success = true;
        foreach ($recipients as $recipient) {
            if (!$recipient instanceof User) {
                if (is_string($recipient) && ctype_digit($recipient)) {
                    $recipient = (int)$recipient;
                }
                if (!is_int($recipient)) {
                    Craft::warning('MailCraft: a private message recipient is not a user or user id; skipped.', __METHOD__);
                    $success = false;
                    continue;
                }
            }

            $variables['recipient'] = $recipient;
            if (!MailCraft::send($resolved, $variables, $delay)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * The unsaved template `send()` uses when none is configured: subject and body straight
     * from the variables, only the private message channel enabled.
     */
    public function buildBuiltInTemplate(array $options): EmailTemplate
    {
        $expires = $options['expires'] ?? null;

        $settings = [
            'enabled' => true,
            'to' => '{{ recipient.id }}',
            'from' => '{{ sender.id ?? "" }}',
            'type' => '{{ type ?? "" }}',
            'pinned' => (bool)($options['pinned'] ?? false),
            'expiresDays' => is_int($expires) && $expires > 0 ? $expires : null,
        ];
        if ($expires instanceof \DateTimeInterface) {
            $settings['dateExpires'] = '{{ (expires ?? null) ? expires|date("Y-m-d H:i:s") : "" }}';
        }
        for ($i = 1; $i <= 5; $i++) {
            $settings['meta' . $i] = '{{ meta' . $i . ' ?? "" }}';
        }
        $settings['data'] = '{{ (data ?? []) is empty ? "" : data|json_encode }}';

        return new EmailTemplate([
            'title' => 'Private message (built-in)',
            'subject' => '{{ subject }}',
            'template' => '{{ content|raw }}',
            'event' => 'manual',
            'enabled' => true,
            'channels' => [
                'email' => ['enabled' => false],
                PrivateMessageChannel::HANDLE => $settings,
            ],
        ]);
    }

    /**
     * Returns a new message query with the defaults (not hidden, not expired, pinned first, newest first).
     */
    public function query(): MessageQuery
    {
        return new MessageQuery();
    }

    /**
     * Returns a message by its id, hidden and expired ones included.
     */
    public function getById(int $id): ?Message
    {
        return $this->query()->id($id)->withHidden()->withExpired()->one();
    }

    /**
     * The reason the last `save()` failed, or null when it succeeded.
     */
    public function getLastError(): ?string
    {
        return $this->_lastError;
    }

    /**
     * Inserts or updates a message. `content` is purified and `subject` truncated to 255 characters.
     */
    public function save(Message $message): bool
    {
        $this->_lastError = null;

        try {
            return (bool)Craft::$app->getDb()->transaction(function() use ($message) {
                if ($message->id) {
                    $record = MessageRecord::findOne($message->id);
                    if ($record === null) {
                        throw new RuntimeException("Message {$message->id} does not exist.");
                    }
                } else {
                    $record = new MessageRecord();
                }

                $message->subject = mb_substr($message->subject, 0, 255);
                $message->content = HtmlPurifier::process($message->content, [
                    'Attr.AllowedRel' => ['nofollow', 'noopener', 'noreferrer'],
                    // a template body like `<p>{{ content|raw }}</p>` (CKEditor wraps the variable in a
                    // paragraph) would otherwise store `<p></p>` before and after the content
                    'AutoFormat.RemoveEmpty' => true,
                ]);

                $record->toId = $message->toId;
                $record->fromId = $message->fromId;
                $record->templateId = $message->templateId;
                $record->siteId = $message->siteId;
                $record->type = $message->type;
                $record->subject = $message->subject;
                $record->content = $message->content;
                $record->pinned = $message->pinned;
                $record->hidden = $message->hidden;
                $record->dateRead = Db::prepareDateForDb($message->dateRead);
                $record->dateExpires = Db::prepareDateForDb($message->dateExpires);
                $record->meta1 = $message->meta1;
                $record->meta2 = $message->meta2;
                $record->meta3 = $message->meta3;
                $record->meta4 = $message->meta4;
                $record->meta5 = $message->meta5;
                $record->data = $message->data !== [] ? Json::encode($message->data) : null;

                if (!$record->save(false)) {
                    throw new RuntimeException('The message record could not be saved.');
                }

                $message->id = (int)$record->id;
                $message->uid = $record->uid;
                $message->dateCreated = DateTimeHelper::toDateTime($record->dateCreated) ?: null;
                $message->dateUpdated = DateTimeHelper::toDateTime($record->dateUpdated) ?: null;

                return true;
            });
        } catch (\Throwable $e) {
            $this->_lastError = Craft::t('mailcraft', 'The message could not be saved.');
            Craft::warning('MailCraft: the private message could not be saved: ' . $e->getMessage(), __METHOD__);
        }

        return false;
    }

    /**
     * Deletes a message.
     *
     * @return bool Whether a row was deleted
     */
    public function delete(int|Message $message): bool
    {
        $id = $this->resolveId($message);
        if ($id === null) {
            return false;
        }

        return (bool)$this->runInTransaction(function() use ($id) {
            return Db::delete(MessageRecord::tableName(), ['id' => $id]) > 0;
        }, false, __METHOD__);
    }

    /**
     * Marks a message read (keeping the first read time) or unread.
     */
    public function markRead(int|Message $message, bool $read = true): bool
    {
        if ($read) {
            $now = new DateTime();
            $result = $this->updateOne($message, ['dateRead' => Db::prepareDateForDb($now)], ['dateRead' => null]);
            if ($result && $message instanceof Message && $message->dateRead === null) {
                $message->dateRead = $now;
            }
        } else {
            $result = $this->updateOne($message, ['dateRead' => null]);
            if ($result && $message instanceof Message) {
                $message->dateRead = null;
            }
        }

        return $result;
    }

    /**
     * Pins or unpins a message.
     */
    public function setPinned(int|Message $message, bool $pinned = true): bool
    {
        $result = $this->updateOne($message, ['pinned' => $pinned]);
        if ($result && $message instanceof Message) {
            $message->pinned = $pinned;
        }

        return $result;
    }

    /**
     * Hides (archives) or unhides a message.
     */
    public function setHidden(int|Message $message, bool $hidden = true): bool
    {
        $result = $this->updateOne($message, ['hidden' => $hidden]);
        if ($result && $message instanceof Message) {
            $message->hidden = $hidden;
        }

        return $result;
    }

    /**
     * Marks every unread message of a user read.
     *
     * @return int Rows updated
     */
    public function markAllRead(User|int $user): int
    {
        $userId = $user instanceof User ? (int)$user->id : $user;

        return (int)$this->runInTransaction(function() use ($userId) {
            return Db::update(MessageRecord::tableName(), [
                'dateRead' => Db::prepareDateForDb(new DateTime()),
            ], [
                'toId' => $userId,
                'dateRead' => null,
            ]);
        }, 0, __METHOD__);
    }

    /**
     * Updates columns on the given messages, limited to those the user received.
     *
     * @param int[] $ids
     * @param array $columns `column => value`
     * @return int Rows updated
     */
    public function updateForUser(int $userId, array $ids, array $columns): int
    {
        if ($ids === [] || $columns === []) {
            return 0;
        }

        return (int)$this->runInTransaction(function() use ($userId, $ids, $columns) {
            return Db::update(MessageRecord::tableName(), $columns, [
                'id' => $ids,
                'toId' => $userId,
            ]);
        }, 0, __METHOD__);
    }

    /**
     * Deletes the given messages, limited to those the user received.
     *
     * @param int[] $ids
     * @return int Rows deleted
     */
    public function deleteForUser(int $userId, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return (int)$this->runInTransaction(function() use ($userId, $ids) {
            return Db::delete(MessageRecord::tableName(), [
                'id' => $ids,
                'toId' => $userId,
            ]);
        }, 0, __METHOD__);
    }

    /**
     * Deletes messages whose expiry date has passed.
     *
     * @return int Rows deleted
     */
    public function purgeExpired(): int
    {
        return (int)$this->runInTransaction(function() {
            return Db::delete(MessageRecord::tableName(), [
                '<', 'dateExpires', Db::prepareDateForDb(new DateTime()),
            ]);
        }, 0, __METHOD__);
    }

    /**
     * Returns the message types as `value => label`.
     *
     * @return array<string,string>
     */
    public function getTypes(): array
    {
        return MailCraft::getInstance()->getSettings()->getPrivateMessageTypes();
    }

    /**
     * Updates one message by id. True when the message exists and the write went through, even if no value changed.
     */
    private function updateOne(int|Message $message, array $columns, array $extraCondition = []): bool
    {
        $id = $this->resolveId($message);
        if ($id === null) {
            return false;
        }

        return (bool)$this->runInTransaction(function() use ($id, $columns, $extraCondition) {
            $exists = (new Query())
                ->from(MessageRecord::tableName())
                ->where(['id' => $id])
                ->exists();
            if (!$exists) {
                return false;
            }

            Db::update(MessageRecord::tableName(), $columns, array_merge(['id' => $id], $extraCondition));

            return true;
        }, false, __METHOD__);
    }

    /**
     * Runs a write in a database transaction. On an exception it logs a warning and returns
     * `$default`, so callers never see the raw database error.
     */
    private function runInTransaction(callable $callback, mixed $default, string $method): mixed
    {
        try {
            return Craft::$app->getDb()->transaction($callback);
        } catch (\Throwable $e) {
            Craft::warning('MailCraft: private message write failed: ' . $e->getMessage(), $method);
        }

        return $default;
    }

    /**
     * The id of a message model, or the id itself; null when there is none.
     */
    private function resolveId(int|Message $message): ?int
    {
        $id = $message instanceof Message ? $message->id : $message;

        return $id ? (int)$id : null;
    }
}
