<?php

namespace frontendservices\mailcraft\elements;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use frontendservices\mailcraft\base\AbstractChannel;
use frontendservices\mailcraft\elements\db\EmailTemplateQuery;
use frontendservices\mailcraft\MailCraft;
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
     * @var array Delivery channels, `handle => ['enabled' => bool, ...channel settings]`.
     */
    public array $channels = [];

    /**
     * What a template from old plugin versions is treated as (pre 1.4.0).
     */
    public const LEGACY_CHANNELS = ['email' => ['enabled' => true]];

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        if ($this->channels === []) {
            $allowed = MailCraft::getInstance()->getSettings()->allowedChannels;
            $this->channels = ['email' => ['enabled' => in_array('email', $allowed, true) || in_array('*', $allowed, true)]];
        } else {
            $this->channels = self::normalizeChannels($this->channels);
        }
    }

    /**
     * Turns a stored `channels` value (JSON string or array) into the canonical shape.
     */
    public static function normalizeChannels(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value) === '' ? null : Json::decodeIfJson($value);
        }

        if (!is_array($value) || $value === []) {
            return self::LEGACY_CHANNELS;
        }

        $normalized = [];
        foreach ($value as $handle => $settings) {
            if (!is_string($handle) || $handle === '') {
                continue;
            }
            if (!is_array($settings)) {
                $settings = [];
            }
            $settings['enabled'] = filter_var($settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $normalized[$handle] = $settings;
        }

        return $normalized === [] ? self::LEGACY_CHANNELS : $normalized;
    }

    public function isChannelEnabled(string $handle): bool
    {
        return !empty($this->channels[$handle]['enabled']);
    }

    /**
     * The channel's per-template settings, without the `enabled` flag.
     */
    public function getChannelSettings(string $handle): array
    {
        $settings = $this->channels[$handle] ?? [];
        unset($settings['enabled']);

        return $settings;
    }

    /**
     * Get enabled channels
     *
     * @return AbstractChannel[]
     */
    public function getEnabledChannels(): array
    {
        return array_filter(
            MailCraft::getInstance()->channelRegistry->getAllowedChannels(),
            fn(string $handle) => $this->isChannelEnabled($handle),
            ARRAY_FILTER_USE_KEY
        );
    }

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
     */
    public function getPreviewTargets(): array
    {
        if (!$this->id) {
            return [];
        }

        return [
            [
                'label' => Craft::t('mailcraft', 'Preview'),
                'url' => UrlHelper::actionUrl('mailcraft/email-templates/preview-iframe', ['templateId' => $this->id]),
            ],
        ];
    }

    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['title', 'subject', 'event', 'template'], 'required'];
        $rules[] = ['to', 'required', 'when' => fn(EmailTemplate $model) => $model->isChannelEnabled('email')
            && array_key_exists('email', MailCraft::getInstance()->channelRegistry->getAllowedChannels())];
        $rules[] = [['title', 'subject'], 'string', 'max' => 255];
        $rules[] = ['delay', 'integer', 'min' => 0];
        $rules[] = ['event', 'in', 'range' => Craft::$app->getModule('mailcraft')->get('eventRegistry')->getAvailableEventsList()];
        $rules[] = ['conditions', 'string'];
        $rules[] = ['channels', 'validateChannels'];

        return $rules;
    }

    /**
     * Lets every enabled, allowed channel validate its own per-template settings.
     */
    public function validateChannels(): void
    {
        foreach ($this->getEnabledChannels() as $channel) {
            $channel->validateTemplateSettings($this);
        }
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
        $record->channels = Json::encode($this->channels);

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
            'channels' => ['label' => Craft::t('mailcraft', 'Channels')],
            'dateCreated' => ['label' => Craft::t('mailcraft', 'Date Created')],
            'dateUpdated' => ['label' => Craft::t('mailcraft', 'Date Updated')],
        ];
    }

    /**
     * @inheritdoc
     */
    protected function attributeHtml(string $attribute): string
    {
        // Craft 5
        if ($attribute === 'channels') {
            return $this->channelsHtml();
        }

        return parent::attributeHtml($attribute);
    }

    /**
     * @inheritdoc
     */
    protected function tableAttributeHtml(string $attribute): string
    {
        // Craft 4
        if ($attribute === 'channels') {
            return $this->channelsHtml();
        }

        return parent::tableAttributeHtml($attribute);
    }

    private function channelsHtml(): string
    {
        return Html::encode(implode(', ', array_map(
            static fn(AbstractChannel $channel) => $channel->getName(),
            $this->getEnabledChannels()
        )));
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