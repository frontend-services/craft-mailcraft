<?php

namespace frontendservices\mailcraft\db;

use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use DateTime;
use frontendservices\mailcraft\models\Message;
use frontendservices\mailcraft\records\MessageRecord;
use yii\base\InvalidArgumentException;
use yii\db\Expression;

/**
 * Queries private messages and returns `Message` models.
 *
 * The chain methods only set flags; the WHERE is built in `prepare()` on a clone, so a
 * default can be undone later in the chain (`withHidden()`, `withExpired()`) and running
 * `count()` and then `all()` on the same query never stacks conditions.
 */
class MessageQuery extends Query
{
    /**
     * @var int|null Recipient id to filter by
     */
    public ?int $toId = null;

    /**
     * @var bool Whether `to(null)` was called, which makes the query return nothing
     */
    public bool $toIsNull = false;

    /**
     * @var int|null Sender id to filter by
     */
    public ?int $fromId = null;

    /**
     * @var bool Whether `sentBy(null)` was called, which makes the query return nothing
     */
    public bool $fromIsNull = false;

    /**
     * @var string|string[]|null Type(s) to filter by
     */
    public mixed $type = null;

    /**
     * @var bool|null true = read only, false = unread only, null = both
     */
    public ?bool $read = null;

    /**
     * @var bool|null Pinned state to filter by, null = both
     */
    public ?bool $pinned = null;

    /**
     * @var bool|null Hidden state to filter by, null = both. Hidden messages are excluded by default.
     */
    public ?bool $hidden = false;

    /**
     * @var bool Whether expired messages are included
     */
    public bool $withExpired = false;

    /**
     * @var array `n => value` conditions on the `metaN` columns
     */
    public array $meta = [];

    public function __construct($config = [])
    {
        parent::__construct($config);

        parent::from(MessageRecord::tableName());
        $this->orderBy(['pinned' => SORT_DESC, 'dateCreated' => SORT_DESC, 'id' => SORT_DESC]);
    }

    /**
     * Filters by recipient. Null makes the query return nothing.
     */
    public function to(User|int|null $user): static
    {
        $id = $user instanceof User ? $user->id : $user;
        $this->toId = $id !== null ? (int)$id : null;
        $this->toIsNull = $id === null;

        return $this;
    }

    /**
     * Filters by sender (`User`, id, or null for no sender filter).
     *
     * Also accepts a table name or list of tables, which is passed to `yii\db\Query::from()`
     * so the base query API keeps working.
     *
     * @param User|int|string|array|null $tables
     */
    public function from($tables): static
    {
        if ($tables instanceof User) {
            $this->fromId = $tables->id !== null ? (int)$tables->id : null;
        } elseif ($tables === null || is_int($tables) || (is_string($tables) && ctype_digit($tables))) {
            $this->fromId = $tables !== null ? (int)$tables : null;
        } else {
            parent::from($tables);
        }

        return $this;
    }

    /**
     * Filters by sender for a "sent" listing. Unlike `from()`, null makes the query return
     * nothing, so a guest never sees anything.
     */
    public function sentBy(User|int|null $user): static
    {
        $id = $user instanceof User ? $user->id : $user;
        $this->fromId = $id !== null ? (int)$id : null;
        $this->fromIsNull = $id === null;

        return $this;
    }

    /**
     * Filters by type: a value, a list of values, or null for any type.
     *
     * @param string|string[]|null $value
     */
    public function type(mixed $value): static
    {
        $this->type = $value;

        return $this;
    }

    /**
     * Only unread messages.
     */
    public function unread(): static
    {
        $this->read = false;

        return $this;
    }

    /**
     * Only read messages.
     */
    public function read(): static
    {
        $this->read = true;

        return $this;
    }

    public function pinned(bool $value = true): static
    {
        $this->pinned = $value;

        return $this;
    }

    public function hidden(bool $value = true): static
    {
        $this->hidden = $value;

        return $this;
    }

    /**
     * Drops the hidden filter.
     */
    public function withHidden(): static
    {
        $this->hidden = null;

        return $this;
    }

    /**
     * Drops the expiry filter.
     */
    public function withExpired(): static
    {
        $this->withExpired = true;

        return $this;
    }

    /**
     * Filters by one of the `meta1` … `meta5` columns.
     */
    public function meta(int $n, mixed $value): static
    {
        if ($n < 1 || $n > 5) {
            throw new InvalidArgumentException('The meta column number must be between 1 and 5.');
        }

        $this->meta[$n] = $value;

        return $this;
    }

    /**
     * Filters by message id(s).
     *
     * @param int|int[] $id
     */
    public function id(int|array $id): static
    {
        $this->andWhere(['id' => $id]);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function prepare($builder)
    {
        $query = clone $this;
        $query->applyFilters();

        return $query->prepareWithoutFilters($builder);
    }

    /**
     * Runs the parent `prepare()` without adding the flag conditions again.
     */
    protected function prepareWithoutFilters($builder)
    {
        return parent::prepare($builder);
    }

    /**
     * @inheritdoc
     * @return int The number of matching messages (PDO returns a string; this always returns an int)
     */
    public function count($q = '*', $db = null): int
    {
        return (int)parent::count($q, $db);
    }

    /**
     * @inheritdoc
     * @return Message[]
     */
    public function all($db = null): array
    {
        $rows = parent::all($db);

        return $this->createModels($rows);
    }

    /**
     * @inheritdoc
     * @return Message|null
     */
    public function one($db = null): ?Message
    {
        $row = parent::one($db);
        if (!is_array($row)) {
            return null;
        }

        $models = $this->createModels([$row]);

        return reset($models) ?: null;
    }

    /**
     * Adds the flag conditions to this query. Only ever called on the clone made in `prepare()`.
     */
    private function applyFilters(): void
    {
        if ($this->toIsNull) {
            $this->andWhere(new Expression('0=1'));
        } elseif ($this->toId !== null) {
            $this->andWhere(['toId' => $this->toId]);
        }

        if ($this->fromIsNull) {
            $this->andWhere(new Expression('0=1'));
        } elseif ($this->fromId !== null) {
            $this->andWhere(['fromId' => $this->fromId]);
        }

        if ($this->type !== null) {
            $this->andWhere(['type' => $this->type]);
        }

        if ($this->read !== null) {
            $this->andWhere($this->read ? ['not', ['dateRead' => null]] : ['dateRead' => null]);
        }

        if ($this->pinned !== null) {
            $this->andWhere(['pinned' => $this->pinned]);
        }

        if ($this->hidden !== null) {
            $this->andWhere(['hidden' => $this->hidden]);
        }

        if (!$this->withExpired) {
            $this->andWhere([
                'or',
                ['dateExpires' => null],
                ['>', 'dateExpires', Db::prepareDateForDb(new DateTime())],
            ]);
        }

        foreach ($this->meta as $n => $value) {
            $this->andWhere(["meta$n" => $value]);
        }
    }

    /**
     * Turns rows into models and eager-loads their senders and recipients with one query.
     *
     * @return Message[]
     */
    private function createModels(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $userIds = [];
        foreach ($rows as $row) {
            foreach (['fromId', 'toId'] as $column) {
                if (!empty($row[$column])) {
                    $userIds[(int)$row[$column]] = true;
                }
            }
        }

        $users = [];
        if ($userIds !== []) {
            $users = User::find()->id(array_keys($userIds))->status(null)->trashed(null)->indexBy('id')->all();
        }

        $models = [];
        foreach ($rows as $key => $row) {
            $record = new MessageRecord();
            MessageRecord::populateRecord($record, $row);
            $model = Message::fromRecord($record);
            $model->setFrom($model->fromId !== null ? ($users[$model->fromId] ?? null) : null);
            $model->setTo($model->toId !== null ? ($users[$model->toId] ?? null) : null);
            $models[$key] = $model;
        }

        return $models;
    }
}
