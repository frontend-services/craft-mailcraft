<?php

namespace frontendservices\mailcraft\events;

use craft\elements\User;
use yii\base\Event;

/**
 * Raised by `MessagesController::actionSend()` before a message from the frontend is sent.
 *
 * Set `$isValid` to false to refuse the message (for example when the recipient blocked the
 * sender). `$subject` and `$content` may be rewritten; the controller sends the event's values.
 *
 * ```php
 * Event::on(MessagesController::class, MessagesController::EVENT_BEFORE_FRONTEND_SEND, function(FrontendSendEvent $e) {
 *     if (isBlocked($e->to, $e->from)) {
 *         $e->isValid = false;
 *     }
 * });
 * ```
 */
class FrontendSendEvent extends Event
{
    /**
     * @var User|null The logged-in user sending the message
     */
    public ?User $from = null;

    /**
     * @var User|null The recipient
     */
    public ?User $to = null;

    /**
     * @var string Subject, trimmed and truncated to 255 characters
     */
    public string $subject = '';

    /**
     * @var string Purified HTML content
     */
    public string $content = '';

    /**
     * @var bool Whether the message may be sent
     */
    public bool $isValid = true;
}
