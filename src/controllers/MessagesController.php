<?php

namespace frontendservices\mailcraft\controllers;

use Craft;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\HtmlPurifier;
use craft\web\Controller;
use DateTime;
use frontendservices\mailcraft\events\FrontendSendEvent;
use frontendservices\mailcraft\MailCraft;
use frontendservices\mailcraft\PrivateMessaging;
use yii\caching\DummyCache;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Frontend actions for the logged-in user's private messages.
 *
 * Every action requires a logged-in user and a POST request, and only touches messages the
 * user received.
 */
class MessagesController extends Controller
{
    /**
     * @event FrontendSendEvent Raised before a message from `actionSend()` is sent. A handler
     * may veto the send or rewrite `subject` and `content`; rewritten values are not validated
     * again by the controller (the service still purifies the content and truncates the
     * subject when the message is saved).
     */
    public const EVENT_BEFORE_FRONTEND_SEND = 'beforeFrontendSend';

    /**
     * Most ids one bulk request may name.
     */
    private const MAX_IDS = 200;

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * @inheritdoc
     * @throws ForbiddenHttpException
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (Craft::$app->getUser()->getIsGuest()) {
            throw new ForbiddenHttpException('You must be logged in.');
        }

        $this->requirePostRequest();

        return true;
    }

    /**
     * Marks the given messages read.
     *
     * @throws BadRequestHttpException
     */
    public function actionMarkRead(): ?Response
    {
        return $this->update(['dateRead' => Db::prepareDateForDb(new DateTime())]);
    }

    /**
     * Marks the given messages unread.
     *
     * @throws BadRequestHttpException
     */
    public function actionMarkUnread(): ?Response
    {
        return $this->update(['dateRead' => null]);
    }

    /**
     * Marks every unread message of the user read.
     */
    public function actionMarkAllRead(): ?Response
    {
        $n = MailCraft::getInstance()->privateMessages->markAllRead($this->currentUserId());

        return $this->asSuccess(Craft::t('mailcraft', 'Updated.'), ['updated' => $n, 'ignored' => 0]);
    }

    /**
     * @throws BadRequestHttpException
     */
    public function actionPin(): ?Response
    {
        return $this->update(['pinned' => true]);
    }

    /**
     * @throws BadRequestHttpException
     */
    public function actionUnpin(): ?Response
    {
        return $this->update(['pinned' => false]);
    }

    /**
     * @throws BadRequestHttpException
     */
    public function actionHide(): ?Response
    {
        return $this->update(['hidden' => true]);
    }

    /**
     * @throws BadRequestHttpException
     */
    public function actionUnhide(): ?Response
    {
        return $this->update(['hidden' => false]);
    }

    /**
     * Deletes the given messages.
     *
     * @throws BadRequestHttpException
     */
    public function actionDelete(): ?Response
    {
        $ids = $this->idsFromRequest();
        $n = MailCraft::getInstance()->privateMessages->deleteForUser($this->currentUserId(), $ids);

        return $this->asSuccess(Craft::t('mailcraft', 'Updated.'), ['updated' => $n, 'ignored' => count($ids) - $n]);
    }

    /**
     * Sends a message from the logged-in user to another user. Only when
     * `allowFrontendSending` is on; the message type is always `frontendSendingType`.
     *
     * @throws ForbiddenHttpException
     */
    public function actionSend(): ?Response
    {
        $settings = MailCraft::getInstance()->getSettings();
        if (!$settings->allowFrontendSending) {
            throw new ForbiddenHttpException('Sending messages from the frontend is not allowed.');
        }

        $request = Craft::$app->getRequest();
        $userId = $this->currentUserId();
        $perHour = (int)$settings->frontendSendingPerHour;

        if (!$this->checkRateLimit($userId, $perHour)) {
            return $this->asFailure(Craft::t('mailcraft', 'Too many messages. Please try again later.'));
        }

        $to = $request->getBodyParam('to');
        $recipient = is_scalar($to) && is_numeric($to)
            ? User::find()->id((int)$to)->status(User::STATUS_ACTIVE)->one()
            : null;
        if (!$recipient instanceof User) {
            return $this->asFailure(Craft::t('mailcraft', 'Recipient not found.'));
        }

        $subject = $request->getBodyParam('subject');
        $subject = is_string($subject) ? trim($subject) : '';
        if ($subject === '') {
            return $this->asFailure(Craft::t('mailcraft', 'Subject is required.'));
        }
        $subject = mb_substr($subject, 0, 255);

        $content = $request->getBodyParam('content');
        $content = is_string($content) ? $content : '';
        // refuse oversized input before it reaches the purifier
        if (mb_strlen($content) > 100000) {
            return $this->asFailure(Craft::t('mailcraft', 'Message is too long.'));
        }
        $content = HtmlPurifier::process($content, [
            'HTML.Allowed' => 'p,br,strong,b,em,i,u,a[href],ul,ol,li,blockquote',
            'AutoFormat.AutoParagraph' => true,
            'AutoFormat.RemoveEmpty' => true,
            'URI.AllowedSchemes' => ['http' => true, 'https' => true, 'mailto' => true],
            'HTML.Nofollow' => true,
            'HTML.TargetNoopener' => true,
        ]);
        if (trim(strip_tags($content)) === '') {
            return $this->asFailure(Craft::t('mailcraft', 'Message is empty.'));
        }
        if (mb_strlen($content) > 20000) {
            return $this->asFailure(Craft::t('mailcraft', 'Message is too long.'));
        }

        /** @var User|null $sender */
        $sender = Craft::$app->getUser()->getIdentity();

        $event = new FrontendSendEvent([
            'from' => $sender,
            'to' => $recipient,
            'subject' => $subject,
            'content' => $content,
        ]);
        $this->trigger(self::EVENT_BEFORE_FRONTEND_SEND, $event);
        if (!$event->isValid) {
            return $this->asFailure(Craft::t('mailcraft', "You can't send a message to this user."));
        }

        $sent = PrivateMessaging::send($event->subject, $event->content, [
            'to' => $recipient,
            'from' => $sender,
            'type' => $settings->frontendSendingType,
        ]);
        if (!$sent) {
            return $this->asFailure(Craft::t('mailcraft', 'The message could not be sent.'));
        }

        if ($perHour !== 0) {
            $this->countSend($userId);
        }

        return $this->asSuccess(Craft::t('mailcraft', 'Message sent.'));
    }

    /**
     * Applies `$columns` to the requested messages the user received.
     *
     * @throws BadRequestHttpException
     */
    private function update(array $columns): ?Response
    {
        $ids = $this->idsFromRequest();
        $n = MailCraft::getInstance()->privateMessages->updateForUser($this->currentUserId(), $ids, $columns);

        return $this->asSuccess(Craft::t('mailcraft', 'Updated.'), ['updated' => $n, 'ignored' => count($ids) - $n]);
    }

    /**
     * Message ids from the `id` or `ids` body param.
     *
     * @return int[]
     * @throws BadRequestHttpException
     */
    private function idsFromRequest(): array
    {
        $request = Craft::$app->getRequest();
        $ids = $request->getBodyParam('ids', $request->getBodyParam('id'));
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));

        if ($ids === []) {
            throw new BadRequestHttpException('No message ids given.');
        }
        if (count($ids) > self::MAX_IDS) {
            throw new BadRequestHttpException('Too many ids.');
        }

        return $ids;
    }

    private function currentUserId(): int
    {
        return (int)Craft::$app->getUser()->getId();
    }

    /**
     * Whether the user may send another message this hour. `$perHour === 0` means no limit.
     */
    private function checkRateLimit(int $userId, int $perHour): bool
    {
        if ($perHour === 0) {
            return true;
        }

        $cache = Craft::$app->getCache();
        if ($cache instanceof DummyCache) {
            $count = MailCraft::getInstance()->privateMessages->query()
                ->from($userId)
                ->withHidden()
                ->withExpired()
                ->andWhere(['>', 'dateCreated', Db::prepareDateForDb(new DateTime('-1 hour'))])
                ->count();

            return $count < $perHour;
        }

        $state = $this->rateLimitState($userId);

        return $state['count'] < $perHour;
    }

    /**
     * Counts one frontend send towards the user's hourly limit.
     */
    private function countSend(int $userId): void
    {
        $cache = Craft::$app->getCache();
        if ($cache instanceof DummyCache) {
            return;
        }

        $state = $this->rateLimitState($userId);
        $until = $state['until'] ?? time() + 3600;
        $ttl = max(1, $until - time());

        $cache->set($this->rateLimitKey($userId), ['count' => $state['count'] + 1, 'until' => $until], $ttl);
    }

    /**
     * The user's current window: `count` sends so far and `until` (null when no window is open).
     *
     * @return array{count:int,until:int|null}
     */
    private function rateLimitState(int $userId): array
    {
        $value = Craft::$app->getCache()->get($this->rateLimitKey($userId));
        if (!is_array($value) || !isset($value['until']) || (int)$value['until'] < time()) {
            return ['count' => 0, 'until' => null];
        }

        return ['count' => (int)($value['count'] ?? 0), 'until' => (int)$value['until']];
    }

    private function rateLimitKey(int $userId): string
    {
        return "mailcraft:frontend-send:$userId";
    }
}
