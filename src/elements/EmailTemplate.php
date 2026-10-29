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
    public ?string $handle = null;
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
            if (array_key_exists('_content', $settings)) {
                $settings['_content'] = self::normalizeContentOverride($settings['_content']);
            }
            $normalized[$handle] = $settings;
        }

        return $normalized === [] ? self::LEGACY_CHANNELS : $normalized;
    }

    /**
     * Turns a channel's `_content` entry (as posted or stored) into
     * `['overrideContent' => bool, 'subject' => string, 'body' => string]`. The subject is
     * capped at 255 characters, like the main subject.
     */
    public static function normalizeContentOverride(mixed $value): array
    {
        if (!is_array($value)) {
            $value = [];
        }

        return [
            'overrideContent' => filter_var($value['overrideContent'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'subject' => is_scalar($value['subject'] ?? null) ? mb_substr((string)$value['subject'], 0, 255) : '',
            'body' => is_scalar($value['body'] ?? null) ? (string)$value['body'] : '',
        ];
    }

    /**
     * Whether the channel has its content override switched on with a non-blank subject or body.
     * Says nothing about whether overrides are available (see EmailService::overridesAvailable()).
     */
    public function hasContentOverride(string $handle): bool
    {
        $override = self::normalizeContentOverride($this->channels[$handle]['_content'] ?? null);

        return $override['overrideContent']
            && (trim($override['subject']) !== '' || trim($override['body']) !== '');
    }

    public function isChannelEnabled(string $handle): bool
    {
        return !empty($this->channels[$handle]['enabled']);
    }

    /**
     * The channel's per-template settings, without the reserved keys (`enabled`, `_content`).
     */
    public function getChannelSettings(string $handle): array
    {
        $settings = $this->channels[$handle] ?? [];
        foreach (AbstractChannel::RESERVED_KEYS as $key) {
            unset($settings[$key]);
        }

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
     * @inheritdoc Templates are not site content; see review item C4.
     */
    public static function isLocalized(): bool
    {
        return false;
    }

    /**
     * @inheritdoc Templates are not site content; see review item C4.
     */
    public static function hasUrls(): bool
    {
        return false;
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
        $rules[] = ['handle', 'trim'];
        $rules[] = ['handle', 'string', 'max' => 255];
        $rules[] = ['handle', 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_-]*$/', 'message' => Craft::t('mailcraft', 'Handles start with a letter and contain only letters, numbers, dashes and underscores.')];
        $rules[] = ['handle', 'validateHandle', 'skipOnEmpty' => true];

        return $rules;
    }

    /**
     * Rejects a handle that another template already uses (case-insensitive).
     */
    public function validateHandle(): void
    {
        $query = EmailTemplateRecord::find()
            ->where(['=', new \yii\db\Expression('LOWER([[handle]])'), mb_strtolower($this->handle)]);

        if ($this->id) {
            $query->andWhere(['not', ['id' => $this->id]]);
        }

        if ($query->exists()) {
            $this->addError('handle', Craft::t('mailcraft', 'Another template already uses this handle.'));
        }
    }

    /**
     * Lets every enabled, allowed channel validate its own per-template settings, and rejects
     * a content override subject longer than the main subject may be.
     */
    public function validateChannels(): void
    {
        foreach ($this->getEnabledChannels() as $channel) {
            $channel->validateTemplateSettings($this);
        }

        foreach ($this->channels as $handle => $settings) {
            if (!filter_var($settings['_content']['overrideContent'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }
            $subject = $settings['_content']['subject'] ?? null;
            if (is_string($subject) && mb_strlen($subject) > 255) {
                $this->addError("channels.$handle._content.subject", Craft::t('yii', '{attribute} should contain at most {max, number} {max, plural, one{character} other{characters}}.', [
                    'attribute' => $this->getAttributeLabel('subject'),
                    'max' => 255,
                ]));
            }
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

        $record->handle = $this->handle ?: null;
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
            'handle' => ['label' => Craft::t('mailcraft', 'Handle')],
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
        return ['title', 'handle', 'subject', 'to', 'toName'];
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