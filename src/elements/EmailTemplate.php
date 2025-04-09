<?php

namespace frontendservices\mailcraft\elements;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\UrlHelper;
use frontendservices\mailcraft\elements\db\EmailTemplateQuery;
use frontendservices\mailcraft\records\EmailTemplateRecord;
use yii\base\InvalidConfigException;
use yii\db\Exception;

/**
 * EmailTemplate element type
 */
class EmailTemplate extends Element
{
    public ?string $subject = null;
    public ?string $event = null;
    public ?int $delay = null;
    public ?string $template = null;
    public ?string $to = null;
    public ?string $toName = null;
    public ?string $cc = null;
    public ?string $bcc = null;
    public ?string $from = null;
    public ?string $fromName = null;
    public ?string $replyTo = null;
    public ?string $conditions = null;
    public ?string $condition1 = null;
    public ?string $condition2 = null;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('mailcraft', 'Email Template');
    }

    /**
     * @inheritdoc
     */
    public static function pluralDisplayName(): string
    {
        return Craft::t('mailcraft', 'Email Templates');
    }

    public static function hasContent(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasTitles(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function isLocalized(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasUrls(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function getStatus(): ?string
    {
        return $this->enabled ? Element::STATUS_ENABLED : Element::STATUS_DISABLED;
    }

    /**
     * @inheritdoc
     */
    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl("mailcraft/email-templates/{$this->id}");
    }

    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['title', 'subject', 'to', 'event', 'template'], 'required'];
        $rules[] = [['title', 'subject'], 'string', 'max' => 255];
        $rules[] = ['delay', 'integer', 'min' => 0];
        $rules[] = ['event', 'in', 'range' => Craft::$app->getModule('mailcraft')->get('eventRegistry')->getAvailableEventsList()];
        $rules[] = ['conditions', 'string'];

        return $rules;
    }

    /**
     * @inheritdoc
     * @throws Exception
     */
    public function afterSave(bool $isNew): void
    {
        if (!$isNew) {
            $record = EmailTemplateRecord::findOne($this->id);

            if (!$record) {
                throw new Exception('Invalid email template ID: ' . $this->id);
            }
        } else {
            $record = new EmailTemplateRecord();
            $record->id = $this->id;
        }

        $record->subject = $this->subject;
        $record->event = $this->event;
        $record->delay = $this->delay;
        $record->template = $this->template;
        $record->to = $this->to;
        $record->toName = $this->toName;
        $record->cc = $this->cc;
        $record->bcc = $this->bcc;
        $record->from = $this->from;
        $record->fromName = $this->fromName;
        $record->replyTo = $this->replyTo;
        $record->conditions = $this->conditions;
        $record->condition1 = $this->condition1;
        $record->condition2 = $this->condition2;

        $record->save(false);

        parent::afterSave($isNew);
    }

    /**
     * @inheritdoc
     */
    public static function find(): ElementQueryInterface
    {
        return new EmailTemplateQuery(static::class);
    }

    /**
     * @inheritdoc
     */
    protected static function defineSources(string $context = null): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('mailcraft', 'All email templates'),
                'criteria' => [],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineTableAttributes(): array
    {
        return [
            'title' => ['label' => Craft::t('mailcraft', 'Title')],
            'subject' => ['label' => Craft::t('mailcraft', 'Subject')],
            'event' => ['label' => Craft::t('mailcraft', 'Event')],
            'to' => ['label' => Craft::t('mailcraft', 'To')],
            'dateCreated' => ['label' => Craft::t('mailcraft', 'Date Created')],
            'dateUpdated' => ['label' => Craft::t('mailcraft', 'Date Updated')],
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['subject', 'event', 'dateCreated'];
    }

    /**
     * @inheritdoc
     */
    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'subject', 'to', 'toName'];
    }

    /**
     * @inerhitdoc
     */
    public function canView(\craft\elements\User $user): bool
    {
        return $user->can('mailcraft:manageEmailTemplates');
    }

    /**
     * @inheritdoc
     */
    public function canDelete(User $user): bool
    {
        return $user->can('mailcraft:manageEmailTemplates');
    }

    /**
     * @inheritdoc
     */
    public function canDuplicate(User $user): bool
    {
        return $user->can('mailcraft:manageEmailTemplates');
    }
}