<?php

namespace frontendservices\mailcraft\models;

use craft\base\Model;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use DateTime;
use frontendservices\mailcraft\MailCraft;
use frontendservices\mailcraft\records\MessageRecord;

/**
 * A private message in a user's inbox.
 *
 * @property-read User|null $to
 * @property-read User|null $from
 * @property-read bool $isRead
 * @property-read bool $isExpired
 * @property-read string $typeLabel
 */
class Message extends Model
{
    public ?int $id = null;
    public ?int $toId = null;
    public ?int $fromId = null;
    public ?int $templateId = null;
    public ?int $siteId = null;
    public ?string $type = null;
    public string $subject = '';
    public string $content = '';
    public bool $pinned = false;
    public bool $hidden = false;
    public ?DateTime $dateRead = null;
    public ?DateTime $dateExpires = null;
    public ?string $meta1 = null;
    public ?string $meta2 = null;
    public ?string $meta3 = null;
    public ?string $meta4 = null;
    public ?string $meta5 = null;

    /**
     * @var array Decoded `data` column, for anything that needs no index
     */
    public array $data = [];
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    private ?User $_to = null;
    private bool $_toLoaded = false;
    private ?User $_from = null;
    private bool $_fromLoaded = false;

    /**
     * Builds a model from its record.
     */
    public static function fromRecord(MessageRecord $record): self
    {
        $model = new self();
        $model->id = $record->id !== null ? (int)$record->id : null;
        $model->toId = $record->toId !== null ? (int)$record->toId : null;
        $model->fromId = $record->fromId !== null ? (int)$record->fromId : null;
        $model->templateId = $record->templateId !== null ? (int)$record->templateId : null;
        $model->siteId = $record->siteId !== null ? (int)$record->siteId : null;
        $model->type = $record->type !== null ? (string)$record->type : null;
        $model->subject = (string)$record->subject;
        $model->content = (string)$record->content;
        $model->pinned = (bool)$record->pinned;
        $model->hidden = (bool)$record->hidden;
        $model->dateRead = DateTimeHelper::toDateTime($record->dateRead) ?: null;
        $model->dateExpires = DateTimeHelper::toDateTime($record->dateExpires) ?: null;
        $model->meta1 = $record->meta1 !== null ? (string)$record->meta1 : null;
        $model->meta2 = $record->meta2 !== null ? (string)$record->meta2 : null;
        $model->meta3 = $record->meta3 !== null ? (string)$record->meta3 : null;
        $model->meta4 = $record->meta4 !== null ? (string)$record->meta4 : null;
        $model->meta5 = $record->meta5 !== null ? (string)$record->meta5 : null;
        $data = $record->data !== null && $record->data !== '' ? Json::decodeIfJson((string)$record->data) : [];
        $model->data = is_array($data) ? $data : [];
        $model->dateCreated = DateTimeHelper::toDateTime($record->dateCreated) ?: null;
        $model->dateUpdated = DateTimeHelper::toDateTime($record->dateUpdated) ?: null;
        $model->uid = $record->uid;

        return $model;
    }

    /**
     * Returns the recipient, or null when the user no longer loads.
     */
    public function getTo(): ?User
    {
        if (!$this->_toLoaded) {
            $this->_toLoaded = true;
            $this->_to = $this->toId ? $this->loadUser($this->toId) : null;
        }

        return $this->_to;
    }

    /**
     * Sets the recipient (used by eager loading).
     */
    public function setTo(?User $user): void
    {
        $this->_to = $user;
        $this->_toLoaded = true;
    }

    /**
     * Returns the sender, or null for a system message or a user that no longer loads.
     */
    public function getFrom(): ?User
    {
        if (!$this->_fromLoaded) {
            $this->_fromLoaded = true;
            $this->_from = $this->fromId ? $this->loadUser($this->fromId) : null;
        }

        return $this->_from;
    }

    /**
     * Sets the sender (used by eager loading).
     */
    public function setFrom(?User $user): void
    {
        $this->_from = $user;
        $this->_fromLoaded = true;
    }

    /**
     * Whether the recipient has read the message.
     */
    public function getIsRead(): bool
    {
        return $this->dateRead !== null;
    }

    /**
     * Whether the message is past its expiry date.
     */
    public function getIsExpired(): bool
    {
        return $this->dateExpires !== null && $this->dateExpires <= new DateTime();
    }

    /**
     * Returns the decoded `data` column.
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Returns the label of the type from the plugin settings, else the raw type.
     */
    public function getTypeLabel(): string
    {
        if ($this->type === null) {
            return '';
        }

        return MailCraft::getInstance()->getSettings()->getPrivateMessageTypes()[$this->type] ?? $this->type;
    }

    /**
     * Loads a user by id, including disabled and soft-deleted ones, so a trashed sender still resolves.
     */
    private function loadUser(int $id): ?User
    {
        /** @var User|null $user */
        $user = User::find()->id($id)->status(null)->trashed(null)->one();

        return $user;
    }
}
