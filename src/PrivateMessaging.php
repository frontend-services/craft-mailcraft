<?php

namespace frontendservices\mailcraft;

use frontendservices\mailcraft\db\MessageQuery;
use frontendservices\mailcraft\models\Message;

/**
 * Static entry point for PHP callers: sending and managing private messages.
 *
 * ```php
 * PrivateMessaging::send('Your order shipped', '<p>…</p>', ['to' => $user, 'type' => 'order']);
 * ```
 *
 * Every method passes straight through to `MailCraft::getInstance()->privateMessages`.
 */
final class PrivateMessaging
{
    /**
     * Sends a private message to one or more users. See `PrivateMessageService::send()` for the options.
     */
    public static function send(string $subject, string $content, array $options = []): bool
    {
        return MailCraft::getInstance()->privateMessages->send($subject, $content, $options);
    }

    public static function query(): MessageQuery
    {
        return MailCraft::getInstance()->privateMessages->query();
    }

    public static function markRead(int|Message $message, bool $read = true): bool
    {
        return MailCraft::getInstance()->privateMessages->markRead($message, $read);
    }

    public static function setPinned(int|Message $message, bool $pinned = true): bool
    {
        return MailCraft::getInstance()->privateMessages->setPinned($message, $pinned);
    }

    public static function setHidden(int|Message $message, bool $hidden = true): bool
    {
        return MailCraft::getInstance()->privateMessages->setHidden($message, $hidden);
    }

    public static function delete(int|Message $message): bool
    {
        return MailCraft::getInstance()->privateMessages->delete($message);
    }
}
