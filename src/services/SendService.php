<?php

namespace frontendservices\mailcraft\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use frontendservices\mailcraft\elements\EmailTemplate;
use frontendservices\mailcraft\helpers\TemplateHelper;
use frontendservices\mailcraft\jobs\SendToAudienceJob;
use frontendservices\mailcraft\MailCraft;
use frontendservices\mailcraft\models\Send;
use frontendservices\mailcraft\records\SendRecord;
use InvalidArgumentException;
use RuntimeException;

/**
 * Sends a template to an audience of users (the Send page)
 */
class SendService extends Component
{
    public const CHUNK_SIZE = 100;
    public const TTR = 3600;
    public const MAX_ATTEMPTS = 3;

    // `to`/`toName` are kept as well: the email channel reads them from the template, not from `channels`
    private const SNAPSHOT_KEYS = ['title', 'subject', 'template', 'event', 'channels', 'to', 'toName'];

    private ?string $lastError = null;

    /**
     * The reason the most recent queue(), cancel() or templateFor() call failed.
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Checn if tempate's to field references the recipient
     */
    public function toReferencesRecipient(EmailTemplate $t): bool
    {
        return str_contains((string)$t->to, 'recipient');
    }

    /**
     * Get enabled channels.
     *
     * @return string[]
     */
    public function availableChannels(EmailTemplate $t): array
    {
        return array_keys($t->getEnabledChannels());
    }

    /**
     * Checks a Send before it is queued.
     *
     * @param bool $checkAudience False skips the audience checks (test sends and previews)
     * @return string[] Translated errors, empty when the send is valid
     */
    public function validateSend(EmailTemplate $t, array $channels, array $audience, bool $checkAudience = true): array
    {
        $audiences = MailCraft::getInstance()->audiences;
        $errors = [];

        $problems = $checkAudience ? $audiences->problems($audience) : [];
        foreach ($problems as $problem) {
            $errors[] = $problem;
        }

        if ($channels === []) {
            $errors[] = Craft::t('mailcraft', 'Choose at least one channel.');
        }

        $available = $this->availableChannels($t);
        foreach ($channels as $handle) {
            if (!in_array($handle, $available, true)) {
                $errors[] = Craft::t('mailcraft', 'Channel “{handle}” is not available on this template.', ['handle' => $handle]);
            }
        }

        if (in_array('email', $channels, true) && !$this->toReferencesRecipient($t)) {
            $errors[] = Craft::t('mailcraft', 'This template’s To field is “{to}”, so it would not go to the selected users. Use {{ recipient.email }} in the template.', [
                'to' => (string)$t->to,
            ]);
        }

        if ($t->id === null) {
            if (trim((string)$t->subject) === '') {
                $errors[] = Craft::t('mailcraft', 'Write a subject.');
            }
            if (trim((string)$t->template) === '') {
                $errors[] = Craft::t('mailcraft', 'Write the message.');
            }
        }

        if ($checkAudience && $problems === [] && $audiences->count($audience) === 0) {
            $errors[] = Craft::t('mailcraft', 'No active user matches this audience.');
        }

        return $errors;
    }

    /**
     * The parts of an unsaved template stored on its send row.
     */
    public function snapshot(EmailTemplate $t): array
    {
        return [
            'title' => $t->title,
            'subject' => $t->subject,
            'template' => $t->template,
            'event' => $t->event,
            'channels' => $t->channels,
            'to' => $t->to,
            'toName' => $t->toName,
        ];
    }

    /**
     * Rebuilds an unsaved template from a snapshot().
     */
    public function fromSnapshot(array $s): EmailTemplate
    {
        $config = array_intersect_key($s, array_flip(self::SNAPSHOT_KEYS));
        $config['channels'] = EmailTemplate::normalizeChannels($config['channels'] ?? []);
        $config['enabled'] = true;

        return new EmailTemplate($config);
    }

    /**
     * Creates a send row and pushes its job.
     *
     * @param int|string|EmailTemplate $template A template element, an id, or a handle
     * @param string[] $channels Channel handles to send through
     * @return Send|null Null when the send is invalid
     */
    public function queue(array $audience, int|string|EmailTemplate $template, array $channels, ?int $senderId = null, ?int $siteId = null): ?Send
    {
        $this->lastError = null;
        $plugin = MailCraft::getInstance();

        $resolved = $plugin->emailService->resolveTemplate($template);
        if ($resolved === null) {
            $this->lastError = Craft::t('mailcraft', 'Template not found.');
            return null;
        }
        if ($resolved->id && !$resolved->enabled) {
            $this->lastError = Craft::t('mailcraft', 'Template "{title}" (#{id}) is missing or disabled.', [
                'title' => $resolved->title,
                'id' => $resolved->id,
            ]);
            return null;
        }

        $audience = $plugin->audiences->normalize($audience);
        $channels = array_values(array_unique(array_map('strval', $channels)));

        $errors = $this->validateSend($resolved, $channels, $audience);
        if ($errors !== []) {
            $this->lastError = implode(' ', $errors);
            return null;
        }

        $record = new SendRecord();
        $record->status = Send::STATUS_QUEUED;
        if ($resolved->id) {
            $record->templateId = (int)$resolved->id;
        } else {
            $record->templateSnapshot = Json::encode($this->snapshot($resolved));
        }
        $record->templateTitle = $resolved->title;
        $record->subject = mb_substr((string)$resolved->subject, 0, 255);
        $record->audience = Json::encode($audience);
        $record->audienceSummary = mb_substr($plugin->audiences->describe($audience), 0, 255);
        $record->channels = Json::encode($channels);
        $record->senderId = $senderId;
        $record->siteId = $siteId ?? (int)Craft::$app->getSites()->getCurrentSite()->id;
        $record->processed = 0;
        $record->sent = 0;
        $record->failed = 0;
        $record->lastUserId = 0;
        $record->save(false);

        try {
            $jobId = Craft::$app->getQueue()->push(new SendToAudienceJob(['sendId' => (int)$record->id]));
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            Db::update(SendRecord::tableName(), [
                'status' => Send::STATUS_FAILED,
                'error' => $e->getMessage(),
            ], ['id' => $record->id]);
            return null;
        }

        $record->jobId = $jobId !== null ? (string)$jobId : null;
        $record->save(false);

        return Send::fromRecord($record);
    }

    /**
     * Delivers a send to its audience, one user at a time, resuming after the row's `lastUserId`. Called by the queue job.
     *
     * @param callable|null $progress `fn(float $fraction, string $label)`, called after every user
     * @throws RuntimeException when the send cannot proceed; the row's status and error are written first
     */
    public function run(Send $send, ?callable $progress = null): void
    {
        $plugin = MailCraft::getInstance();

        $send = $this->getById((int)$send->id) ?? throw new RuntimeException('MailCraft: send #' . $send->id . ' not found.');
        $id = (int)$send->id;

        $columns = ['status' => Send::STATUS_RUNNING, 'dateFinished' => null];
        if ($send->status === Send::STATUS_FAILED) {
            $columns['error'] = null;
            $send->error = null;
        }
        if ($send->dateStarted === null) {
            $columns['dateStarted'] = Db::prepareDateForDb(new DateTime());
        }
        $claimed = Db::update(SendRecord::tableName(), $columns, [
            'id' => $id,
            'status' => [Send::STATUS_QUEUED, Send::STATUS_RUNNING, Send::STATUS_FAILED],
        ]);
        if ($claimed === 0) {
            $current = (new Query())
                ->select(['status'])
                ->from(SendRecord::tableName())
                ->where(['id' => $id])
                ->scalar();
            if ($current !== Send::STATUS_RUNNING) {
                return;
            }
        }

        $template = $this->templateFor($send);
        if ($template === null) {
            $this->abort($id, (string)$this->lastError);
        }

        $errors = $this->validateSend($template, $send->channels, $send->audience, false);
        if ($errors !== []) {
            $this->abort($id, implode(' ', $errors));
        }

        try {
            $query = $plugin->audiences->query($send->audience);
        } catch (InvalidArgumentException $e) {
            $this->abort($id, $e->getMessage(), $e);
        }

        $enabled = $template->getEnabledChannels();
        $channels = [];
        foreach ($send->channels as $handle) {
            $channels[$handle] = $enabled[$handle];
        }

        $total = $send->total;
        if ($total === null) {
            $total = (int)(clone $query)->count();
            Db::update(SendRecord::tableName(), ['total' => $total], ['id' => $id]);
        }

        $sender = $send->senderId ? User::find()->id($send->senderId)->status(null)->one() : null;
        $runtimeVariables = TemplateHelper::getRuntimeVariables();
        $emailService = $plugin->emailService;
        $statusService = $plugin->statusService;

        $processed = $send->processed;
        $sent = $send->sent;
        $failed = $send->failed;
        $lastUserId = $send->lastUserId;
        $error = $send->error;

        $sites = Craft::$app->getSites();
        $oldSite = $sites->getCurrentSite();
        $oldLanguage = Craft::$app->language;

        try {
            $site = $send->siteId ? $sites->getSiteById($send->siteId) : null;
            if ($site !== null) {
                $sites->setCurrentSite($site);
                Craft::$app->language = $site->language;
            }

            do {
                $status = (new Query())
                    ->select(['status'])
                    ->from(SendRecord::tableName())
                    ->where(['id' => $id])
                    ->scalar();
                if ($status === Send::STATUS_CANCELLED) {
                    return;
                }

                $users = (clone $query)
                    ->andWhere(['>', 'elements.id', $lastUserId])
                    ->limit(self::CHUNK_SIZE)
                    ->all();

                /** @var User $user */
                foreach ($users as $user) {
                    $variables = [
                        'recipient' => $user,
                        'sender' => $sender,
                        'subject' => '',
                        'content' => '',
                        'type' => '',
                    ] + $runtimeVariables;

                    $rendered = [];
                    try {
                        foreach ($channels as $handle => $channel) {
                            $content = $emailService->renderContentFor($template, $handle, $variables);
                            $rendered[$handle] = [$content, $channel->buildDeliveries($template, $content, $variables)];
                        }
                    } catch (\Throwable $e) {
                        $this->abort($id, $e->getMessage(), $e);
                    }

                    foreach ($rendered as $handle => [$content, $payloads]) {
                        $channel = $channels[$handle];
                        foreach ($payloads as $payload) {
                            $statusId = $statusService->isRecording($handle)
                                ? $statusService->record($channel, $template, $content, $payload, StatusService::STATUS_QUEUED, null, null, $id)
                                : null;

                            if ($channel->deliver($payload)) {
                                $statusService->markSent($statusId);
                                $sent++;
                            } else {
                                $error = $channel->getLastError() ?? Craft::t('mailcraft', 'unknown error');
                                $statusService->markFailed($statusId, $error);
                                $failed++;
                            }
                        }
                    }

                    $processed++;
                    $lastUserId = (int)$user->id;
                    Db::update(SendRecord::tableName(), [
                        'processed' => $processed,
                        'sent' => $sent,
                        'failed' => $failed,
                        'lastUserId' => $lastUserId,
                        'error' => $error,
                    ], ['id' => $id]);

                    if ($progress !== null) {
                        $progress(
                            $total > 0 ? min(1, $processed / $total) : 1,
                            Craft::t('mailcraft', '{n} of {total}', ['n' => $processed, 'total' => $total])
                        );
                    }
                }
            } while (count($users) === self::CHUNK_SIZE);
        } finally {
            $sites->setCurrentSite($oldSite);
            Craft::$app->language = $oldLanguage;
        }

        Db::update(SendRecord::tableName(), [
            'status' => Send::STATUS_DONE,
            'dateFinished' => Db::prepareDateForDb(new DateTime()),
        ], ['id' => $id]);
    }

    /**
     * Cancels a send that is still queued
     */
    public function cancel(int $id): bool
    {
        $this->lastError = null;

        $send = $this->getById($id);
        if ($send === null) {
            $this->lastError = Craft::t('mailcraft', 'Send not found.');
            return false;
        }

        if ($send->status !== Send::STATUS_QUEUED) {
            $this->lastError = Craft::t('mailcraft', 'This send is no longer queued.');
            return false;
        }

        $reason = Craft::t('mailcraft', 'Cancelled');
        try {
            $identity = Craft::$app->getUser()->getIdentity();
            if ($identity !== null) {
                $reason = Craft::t('mailcraft', 'Cancelled by {user}', ['user' => $identity->username ?: $identity->email]);
            }
        } catch (\Throwable) {
        }

        try {
            if ($send->jobId) {
                $queue = Craft::$app->getQueue();
                if (!method_exists($queue, 'release')) {
                    $this->lastError = Craft::t('mailcraft', 'The queue driver cannot release jobs.');
                    return false;
                }

                $jobStatus = $queue->status($send->jobId);
                if ($jobStatus === \yii\queue\Queue::STATUS_RESERVED) {
                    $this->lastError = Craft::t('mailcraft', 'This send is already running.');
                    return false;
                }
                if ($jobStatus === \yii\queue\Queue::STATUS_DONE) {
                    $this->lastError = Craft::t('mailcraft', "This send's job has already finished.");
                    return false;
                }

                $queue->release((string)$send->jobId);
            }

            $updated = Db::update(SendRecord::tableName(), [
                'status' => Send::STATUS_CANCELLED,
                'error' => $reason,
                'dateFinished' => Db::prepareDateForDb(new DateTime()),
            ], [
                'id' => $id,
                'status' => Send::STATUS_QUEUED,
            ]);
        } catch (\Throwable $e) {
            Craft::warning('MailCraft send: ' . $e->getMessage(), __METHOD__);
            $this->lastError = $e->getMessage();
            return false;
        }

        if ($updated === 0) {
            $this->lastError = Craft::t('mailcraft', 'This send is no longer queued.');
            return false;
        }

        return true;
    }

    public function getById(int $id): ?Send
    {
        $record = SendRecord::findOne($id);

        return $record !== null ? Send::fromRecord($record) : null;
    }

    /**
     * The most recent sends, newest first.
     *
     * @return Send[]
     */
    public function getRecent(int $limit = 20): array
    {
        $sends = [];
        /** @var SendRecord $record */
        foreach (SendRecord::find()->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])->limit($limit)->all() as $record) {
            $sends[] = Send::fromRecord($record);
        }

        return $sends;
    }

    /**
     * The template a send delivers - the saved template or the one rebuilt from the row's snapshot.
     */
    public function templateFor(Send $send): ?EmailTemplate
    {
        $this->lastError = null;

        if ($send->templateId === null && $send->templateSnapshot !== null) {
            return $this->fromSnapshot($send->templateSnapshot);
        }

        $template = $send->templateId !== null
            ? EmailTemplate::find()->id($send->templateId)->status(null)->one()
            : null;

        if ($template === null || !$template->enabled) {
            $this->lastError = Craft::t('mailcraft', 'Template "{title}" (#{id}) is missing or disabled.', [
                'title' => $send->templateTitle,
                'id' => $send->templateId ?? '?',
            ]);
            return null;
        }

        return $template;
    }

    /**
     * Marks the send failed with the error and throws.
     *
     * @throws RuntimeException always
     */
    private function abort(int $id, string $error, ?\Throwable $previous = null): void
    {
        Db::update(SendRecord::tableName(), [
            'status' => Send::STATUS_FAILED,
            'error' => $error,
            'dateFinished' => Db::prepareDateForDb(new DateTime()),
        ], ['id' => $id]);

        throw new RuntimeException($error, 0, $previous);
    }
}
