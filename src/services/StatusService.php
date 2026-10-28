<?php

namespace frontendservices\mailcraft\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use yii\db\Expression;
use frontendservices\mailcraft\base\AbstractChannel;
use frontendservices\mailcraft\elements\EmailTemplate;
use frontendservices\mailcraft\jobs\SendEmailJob;
use frontendservices\mailcraft\MailCraft;
use frontendservices\mailcraft\models\Status;
use frontendservices\mailcraft\records\StatusRecord;

/**
 * Writes and reads the delivery log (`mailcraft_statuses`).
 *
 * Every write is wrapped so a failure (including a missing table) is logged and never
 * stops a send.
 */
class StatusService extends Component
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Whether deliveries through the given channel are logged.
     */
    public function isRecording(string $channel): bool
    {
        return MailCraft::getInstance()->getSettings()->isStatusChannel($channel);
    }

    /**
     * Logs one delivery payload.
     *
     * @param array $content `['subject' => string, 'body' => string]`, already rendered
     * @param DateTime|null $dateSent When the delivery is due (now + delay); null means now.
     * markSent() replaces it with the actual time.
     * @return int|null The new row's id, or null when the channel is not recording or the write failed
     */
    public function record(AbstractChannel $channel, EmailTemplate $template, array $content, array $payload, string $status = self::STATUS_QUEUED, ?string $error = null, ?DateTime $dateSent = null): ?int
    {
        try {
            if (!$this->isRecording($channel->getHandle())) {
                return null;
            }

            // a savepoint when the host is already in a transaction, so a failed write
            // cannot poison it (Postgres)
            return Craft::$app->getDb()->transaction(function() use ($channel, $template, $content, $payload, $status, $error, $dateSent) {
                $record = new StatusRecord();
                $record->templateId = $template->id;
                $record->templateTitle = $template->title;
                $record->channel = $channel->getHandle();
                $record->status = $status;
                $record->subject = mb_substr((string)($content['subject'] ?? ''), 0, 255);
                $record->recipient = mb_substr($channel->describeRecipient($payload), 0, 255);
                $record->payload = Json::encode($payload);
                $record->error = $error;
                $record->attempts = 0;
                $record->dateSent = Db::prepareDateForDb($dateSent ?? new DateTime());

                if (!$record->save(false)) {
                    return null;
                }

                return (int)$record->id;
            });
        } catch (\Throwable $e) {
            Craft::warning('MailCraft status: ' . $e->getMessage(), __METHOD__);
        }

        return null;
    }

    /**
     * Marks a row as queued. The job id and error are only overwritten when given.
     */
    public function markQueued(?int $id, ?string $jobId = null, ?string $error = null): void
    {
        if ($id === null) {
            return;
        }

        try {
            Craft::$app->getDb()->transaction(function() use ($id, $jobId, $error) {
                $record = $this->findRecord($id);
                if ($record === null) {
                    return;
                }

                $record->status = self::STATUS_QUEUED;
                if ($jobId !== null) {
                    $record->jobId = $jobId;
                }
                if ($error !== null) {
                    $record->error = $error;
                }
                $record->save(false);
            });
        } catch (\Throwable $e) {
            Craft::warning('MailCraft status: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Stores the queue job id on a row, but only while the row is still queued: a fast
     * worker may already have marked it sent or failed.
     */
    public function setJobId(?int $id, ?string $jobId): void
    {
        if ($id === null) {
            return;
        }

        try {
            Craft::$app->getDb()->transaction(function() use ($id, $jobId) {
                Db::update(StatusRecord::tableName(), ['jobId' => $jobId], [
                    'id' => $id,
                    'status' => self::STATUS_QUEUED,
                ]);
            });
        } catch (\Throwable $e) {
            Craft::warning('MailCraft status: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Marks a row as delivered.
     */
    public function markSent(?int $id): void
    {
        if ($id === null) {
            return;
        }

        try {
            Craft::$app->getDb()->transaction(function() use ($id) {
                $record = $this->findRecord($id);
                if ($record === null) {
                    return;
                }

                $record->status = self::STATUS_SENT;
                $record->dateSent = Db::prepareDateForDb(new DateTime());
                $record->error = null;
                $record->attempts = (int)$record->attempts + 1;
                $record->save(false);
            });
        } catch (\Throwable $e) {
            Craft::warning('MailCraft status: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Marks a row as failed.
     */
    public function markFailed(?int $id, string $error): void
    {
        if ($id === null) {
            return;
        }

        try {
            Craft::$app->getDb()->transaction(function() use ($id, $error) {
                $record = $this->findRecord($id);
                if ($record === null) {
                    return;
                }

                if ($record->status === self::STATUS_QUEUED) {
                    $record->attempts = (int)$record->attempts + 1;
                }
                $record->status = self::STATUS_FAILED;
                $record->error = $error;
                $record->save(false);
            });
        } catch (\Throwable $e) {
            Craft::warning('MailCraft status: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Returns a row by its id.
     */
    public function getById(int $id): ?Status
    {
        $record = StatusRecord::findOne($id);

        return $record !== null ? Status::fromRecord($record) : null;
    }

    public const SORT_CREATED = 'dateCreated';
    public const SORT_SENT = 'dateSent';

    /**
     * Returns rows matching the criteria, newest first.
     *
     * @param array $criteria `channel`, `status` and/or `search` (subject and recipient);
     * `sort` is `dateCreated` (default) or `dateSent` (the due time for rows not sent yet,
     * the actual time for sent ones), latest first, so deliveries scheduled furthest ahead
     * come first
     * @return Status[]
     */
    public function find(array $criteria = [], int $limit = 50, int $offset = 0): array
    {
        if (($criteria['sort'] ?? null) === self::SORT_SENT) {
            // rows recorded before 1.5.0's due-time semantics may have no dateSent; keep them
            // last on MySQL and Postgres alike (a bare `dateSent DESC` differs between the two)
            $orderBy = [
                new Expression('[[dateSent]] IS NULL ASC'),
                'dateSent' => SORT_DESC,
                'dateCreated' => SORT_DESC,
                'id' => SORT_DESC,
            ];
        } else {
            $orderBy = ['dateCreated' => SORT_DESC, 'id' => SORT_DESC];
        }

        $query = StatusRecord::find()
            ->orderBy($orderBy)
            ->limit($limit)
            ->offset($offset);
        $this->applyCriteria($query, $criteria);

        $statuses = [];
        /** @var StatusRecord $record */
        foreach ($query->all() as $record) {
            $statuses[] = Status::fromRecord($record);
        }

        return $statuses;
    }

    /**
     * Counts rows matching the criteria.
     *
     * @param array $criteria See find()
     */
    public function count(array $criteria = []): int
    {
        $query = StatusRecord::find();
        $this->applyCriteria($query, $criteria);

        return (int)$query->count();
    }

    /**
     * Returns the distinct channel handles that appear in the log.
     *
     * @return string[]
     */
    public function getChannelHandlesInUse(): array
    {
        return (new Query())
            ->select(['channel'])
            ->distinct()
            ->from(StatusRecord::tableName())
            ->orderBy(['channel' => SORT_ASC])
            ->column();
    }

    /**
     * Returns the subset of the given ids that have been resent at least once.
     *
     * @param int[] $ids
     * @return int[]
     */
    public function getResentIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $resent = (new Query())
            ->select(['resentFromId'])
            ->distinct()
            ->from(StatusRecord::tableName())
            ->where(['resentFromId' => $ids])
            ->column();

        return array_map('intval', $resent);
    }

    /**
     * Sends the given rows again through the queue, each as a new row.
     *
     * @param int[] $ids
     * @return array `['resent' => int, 'skipped' => [id => reason]]`
     */
    public function resend(array $ids): array
    {
        $resent = 0;
        $skipped = [];
        $registry = MailCraft::getInstance()->channelRegistry;

        foreach ($ids as $id) {
            $id = (int)$id;
            $newId = null;

            try {
                $status = $this->getById($id);
                if ($status === null) {
                    $skipped[$id] = Craft::t('mailcraft', 'not found');
                    continue;
                }

                if ($status->status === self::STATUS_QUEUED) {
                    $skipped[$id] = Craft::t('mailcraft', 'still queued');
                    continue;
                }

                if ($registry->getChannel($status->channel) === null) {
                    $skipped[$id] = Craft::t('mailcraft', 'channel "{handle}" is not installed', [
                        'handle' => $status->channel,
                    ]);
                    continue;
                }

                $newId = Craft::$app->getDb()->transaction(function() use ($status) {
                    $record = new StatusRecord();
                    $record->templateId = $status->templateId;
                    $record->templateTitle = $status->templateTitle;
                    $record->channel = $status->channel;
                    $record->status = self::STATUS_QUEUED;
                    $record->subject = $status->subject;
                    $record->recipient = $status->recipient;
                    $record->payload = $status->payload;
                    $record->error = null;
                    $record->attempts = 0;
                    $record->jobId = null;
                    $record->resentFromId = $status->id;
                    $record->dateSent = Db::prepareDateForDb(new DateTime());
                    $record->save(false);

                    return (int)$record->id;
                });

                $jobId = Craft::$app->getQueue()->push(new SendEmailJob([
                    'channel' => $status->channel,
                    'payload' => $status->getPayload(),
                    'statusId' => $newId,
                    'description' => 'MailCraft Resend - ' . $status->subject,
                ]));

                // the copy was inserted as queued; only the job id is filled in
                $this->setJobId($newId, $jobId !== null ? (string)$jobId : null);
                $resent++;
            } catch (\Throwable $e) {
                Craft::warning('MailCraft status: ' . $e->getMessage(), __METHOD__);
                $skipped[$id] = $e->getMessage();
                if ($newId !== null) {
                    // the copy exists but its job was never queued
                    $this->markFailed($newId, $e->getMessage());
                }
            }
        }

        return ['resent' => $resent, 'skipped' => $skipped];
    }

    /**
     * Cancels pending deliveries: releases each row's queue job and marks the row cancelled
     * with "Cancelled by <user>" as the reason. Rows that are not queued, whose job is already running,
     * or whose job has already finished are skipped with a reason.
     *
     * @param int[] $ids
     * @return array{cancelled: int, skipped: array<int, string>}
     */
    public function cancel(array $ids): array
    {
        $cancelled = 0;
        $skipped = [];
        $queue = Craft::$app->getQueue();

        $reason = Craft::t('mailcraft', 'Cancelled');
        try {
            // no user component on console requests
            $identity = Craft::$app->getUser()->getIdentity();
            if ($identity !== null) {
                $reason = Craft::t('mailcraft', 'Cancelled by {user}', ['user' => $identity->username ?: $identity->email]);
            }
        } catch (\Throwable) {
        }

        foreach ($ids as $id) {
            $id = (int)$id;

            try {
                $status = $this->getById($id);
                if ($status === null) {
                    $skipped[$id] = Craft::t('mailcraft', 'not found');
                    continue;
                }

                if ($status->status !== self::STATUS_QUEUED) {
                    $skipped[$id] = Craft::t('mailcraft', 'not queued');
                    continue;
                }

                if ($status->jobId) {
                    if (!method_exists($queue, 'release')) {
                        $skipped[$id] = Craft::t('mailcraft', 'the queue driver cannot release jobs');
                        continue;
                    }

                    $jobStatus = $queue->status($status->jobId);
                    if ($jobStatus === \yii\queue\Queue::STATUS_RESERVED) {
                        $skipped[$id] = Craft::t('mailcraft', 'already being sent');
                        continue;
                    }
                    if ($jobStatus === \yii\queue\Queue::STATUS_DONE) {
                        $skipped[$id] = Craft::t('mailcraft', 'its job has already finished');
                        continue;
                    }

                    $queue->release((string)$status->jobId);
                }

                // only while still queued, so a worker that finished meanwhile is not overwritten
                $updated = Craft::$app->getDb()->transaction(function() use ($id, $reason) {
                    return Db::update(StatusRecord::tableName(), [
                        'status' => self::STATUS_CANCELLED,
                        'error' => $reason,
                    ], [
                        'id' => $id,
                        'status' => self::STATUS_QUEUED,
                    ]);
                });

                if ($updated > 0) {
                    $cancelled++;
                } else {
                    $skipped[$id] = Craft::t('mailcraft', 'not queued');
                }
            } catch (\Throwable $e) {
                Craft::warning('MailCraft status: ' . $e->getMessage(), __METHOD__);
                $skipped[$id] = $e->getMessage();
            }
        }

        return ['cancelled' => $cancelled, 'skipped' => $skipped];
    }

    /**
     * Deletes rows from the log. Never touches the queue.
     *
     * @param int[] $ids
     * @return int Rows deleted
     */
    public function delete(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        try {
            return (int)Craft::$app->getDb()->transaction(function() use ($ids) {
                return Db::delete(StatusRecord::tableName(), ['id' => array_map('intval', $ids)]);
            });
        } catch (\Throwable $e) {
            Craft::warning('MailCraft status: ' . $e->getMessage(), __METHOD__);
        }

        return 0;
    }

    /**
     * Deletes `sent`, `failed` and `cancelled` rows older than the retention setting. `queued` rows are
     * never purged: their job may still run.
     *
     * @return int Rows deleted
     */
    public function purge(): int
    {
        try {
            $days = MailCraft::getInstance()->getSettings()->statusRetentionDays;
            if ($days <= 0) {
                return 0;
            }

            return (int)Craft::$app->getDb()->transaction(function() use ($days) {
                return Db::delete(StatusRecord::tableName(), [
                    'and',
                    ['status' => [self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_CANCELLED]],
                    ['<', 'dateUpdated', Db::prepareDateForDb(new DateTime("-{$days} days"))],
                ]);
            });
        } catch (\Throwable $e) {
            Craft::warning('MailCraft status: ' . $e->getMessage(), __METHOD__);
        }

        return 0;
    }

    /**
     * Whether the log has any rows. False when the table cannot be read.
     */
    public function hasRows(): bool
    {
        try {
            return StatusRecord::find()->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function findRecord(?int $id): ?StatusRecord
    {
        if ($id === null) {
            return null;
        }

        return StatusRecord::findOne($id);
    }

    /**
     * @param \yii\db\QueryInterface $query
     */
    private function applyCriteria($query, array $criteria): void
    {
        if (!empty($criteria['channel'])) {
            $query->andWhere(['channel' => (string)$criteria['channel']]);
        }

        if (!empty($criteria['status'])) {
            $query->andWhere(['status' => (string)$criteria['status']]);
        }

        if (isset($criteria['search']) && (string)$criteria['search'] !== '') {
            $search = (string)$criteria['search'];
            // Yii builds a plain (case-sensitive) LIKE on Postgres; ILIKE must be asked for
            $op = Craft::$app->getDb()->getIsPgsql() ? 'ilike' : 'like';
            $query->andWhere(['or', [$op, 'subject', $search], [$op, 'recipient', $search]]);
        }
    }
}
