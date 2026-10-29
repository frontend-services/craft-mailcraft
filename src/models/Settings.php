<?php

namespace frontendservices\mailcraft\models;

use Craft;
use craft\base\Model;
use frontendservices\mailcraft\MailCraft;

/**
 * MailCraft settings
 */
class Settings extends Model
{
    /**
     * @var string The plugin name as it should be displayed in the control panel
     */
    public string $pluginName = 'MailCraft';

    /**
     * @var bool Whether to use CKEditor for the email template editor if CKEditor plugin is installed
     */
    public bool $useWysiwyg = true;

    /**
     * @var bool Whether to show CC and BCC fields in the email template editor
     */
    public bool $showCcBcc = false;

    /**
     * @var bool Whether emails whose delay is "Instantly" are sent during the triggering request
     * instead of being pushed to the queue. Delayed emails always go through the queue.
     */
    public bool $sendInstantEmailsDirectly = false;

    /**
     * @var string[] Handles of the delivery channels that may be used on templates.
     * Email is the default so sites from before channels existed keep sending.
     */
    public array $allowedChannels = ['email'];

    /**
     * @var array Global settings per channel, `handle => array` (e.g. a Slack webhook URL).
     */
    public array $channelSettings = [];

    /**
     * @var string[] Handles of the channels whose sends are recorded on the Status page, or `['*']`
     * (config file only) for every channel. Empty by default so upgraded sites record nothing.
     */
    public array $statusChannels = [];

    /**
     * @var int Days after its last update that a sent or failed status is removed. 0 keeps them forever.
     */
    public int $statusRetentionDays = 30;

    /**
     * @var array Labels for the private message `type` value, stored as `label => value`.
     * Empty by default; a type without a label shows its raw value.
     */
    public array $privateMessageTypes = [];

    /**
     * @var mixed Template used by the `send()` facade (id or handle); null means the built-in simple message.
     */
    public mixed $privateMessageTemplate = null;

    /**
     * @var bool Whether logged-in users may send messages through the `mailcraft/messages/send` action.
     */
    public bool $allowFrontendSending = false;

    /**
     * @var int Messages one user may send through the frontend action per hour. 0 means no limit.
     */
    public int $frontendSendingPerHour = 30;

    /**
     * @var string|null Type written on messages sent through the frontend action.
     */
    public ?string $frontendSendingType = 'user';

    /**
     * @var mixed|null CKEditor plugin instance if available
     */
    public mixed $ckeditor = null;

    /**
     * @inheritdoc
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        if (is_array($values) && array_key_exists('allowedChannels', $values)) {
            $values['allowedChannels'] = $this->normalizeHandleList($values['allowedChannels']);
        }

        if (is_array($values) && array_key_exists('statusChannels', $values)) {
            $values['statusChannels'] = $this->normalizeHandleList($values['statusChannels']);
        }

        if (is_array($values) && array_key_exists('statusRetentionDays', $values)) {
            // typed int property: blank or non-numeric input falls back to the default instead of throwing a TypeError
            $values['statusRetentionDays'] = is_numeric($values['statusRetentionDays']) ? (int)$values['statusRetentionDays'] : 30;
        }

        if (is_array($values) && array_key_exists('channelSettings', $values) && !is_array($values['channelSettings'])) {
            $values['channelSettings'] = [];
        }

        if (is_array($values) && array_key_exists('privateMessageTypes', $values)) {
            $values['privateMessageTypes'] = $this->normalizeTypes($values['privateMessageTypes']);
        }

        if (is_array($values) && array_key_exists('privateMessageTemplate', $values)) {
            $template = $values['privateMessageTemplate'];
            if (is_string($template)) {
                $template = trim($template);
            }
            if ($template === '' || $template === null || (!is_scalar($template))) {
                $template = null;
            } elseif (is_numeric($template)) {
                $template = (int)$template;
            }
            $values['privateMessageTemplate'] = $template;
        }

        if (is_array($values) && array_key_exists('allowFrontendSending', $values)) {
            $values['allowFrontendSending'] = (bool)$values['allowFrontendSending'];
        }

        if (is_array($values) && array_key_exists('frontendSendingPerHour', $values)) {
            $values['frontendSendingPerHour'] = is_numeric($values['frontendSendingPerHour']) ? (int)$values['frontendSendingPerHour'] : 30;
        }

        if (is_array($values) && array_key_exists('frontendSendingType', $values)) {
            $type = $values['frontendSendingType'];
            $type = is_scalar($type) ? trim((string)$type) : '';
            $values['frontendSendingType'] = $type !== '' ? $type : null;
        }

        parent::setAttributes($values, $safeOnly);
    }

    /**
     * The global settings of one channel.
     */
    public function getChannelSettings(string $handle): array
    {
        $settings = $this->channelSettings[$handle] ?? [];

        return is_array($settings) ? $settings : [];
    }

    /**
     * Returns the private message types as `value => label`. When two labels share a value,
     * the later one wins.
     *
     * @return array<string,string>
     */
    public function getPrivateMessageTypes(): array
    {
        $types = [];
        foreach ($this->privateMessageTypes as $label => $value) {
            if (is_scalar($value)) {
                $types[(string)$value] = (string)$label;
            }
        }

        return $types;
    }

    /**
     * Whether sends through the channel are recorded on the Status page.
     */
    public function isStatusChannel(string $handle): bool
    {
        return in_array('*', $this->statusChannels, true) || in_array($handle, $this->statusChannels, true);
    }

    /**
     * Accepts `'*'` (every registered channel, resolved lazily by the registry because the
     * registry does not exist yet while config-file settings are applied), a single handle,
     * or a list that may contain the empty value Craft's checkbox select posts, and returns
     * a clean list of handles.
     */
    private function normalizeHandleList(mixed $value): array
    {
        if ($value === '*') {
            return ['*'];
        }

        if (!is_array($value)) {
            $value = $value === null ? [] : [$value];
        }

        return array_values(array_filter(
            array_map(static fn($handle) => is_scalar($handle) ? (string)$handle : '', $value),
            static fn(string $handle) => $handle !== ''
        ));
    }

    /**
     * Normalises the private message types to the stored `label => value` map.
     */
    private function normalizeTypes(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $types = [];
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $typeValue = $entry['value'] ?? '';
                $label = $entry['label'] ?? '';
            } elseif (is_string($key)) {
                $typeValue = $entry;
                $label = $key;
            } else {
                $typeValue = $entry;
                $label = '';
            }

            $typeValue = is_scalar($typeValue) ? trim((string)$typeValue) : '';
            $label = is_scalar($label) ? trim((string)$label) : '';
            if ($typeValue === '') {
                continue;
            }

            $types[$label !== '' ? $label : $typeValue] = $typeValue;
        }

        return $types;
    }

    public function validateAllowedChannels(): void
    {
        if ($this->allowedChannels === []) {
            $this->addError('allowedChannels', Craft::t('mailcraft', 'At least one channel must be allowed.'));
            return;
        }

        $registered = MailCraft::getInstance()->channelRegistry->getChannels();
        foreach ($this->allowedChannels as $handle) {
            if ($handle !== '*' && !isset($registered[$handle])) {
                $this->addError('allowedChannels', Craft::t('mailcraft', 'Unknown channel “{handle}”.', ['handle' => $handle]));
            }
        }
    }

    /**
     * Silently drops handles that are not registered channels; `'*'` is kept.
     */
    public function validateStatusChannels(): void
    {
        $registered = MailCraft::getInstance()->channelRegistry->getChannels();
        $this->statusChannels = array_values(array_filter(
            $this->statusChannels,
            static fn($handle) => $handle === '*' || isset($registered[$handle])
        ));
    }

    public function validateChannelSettings(): void
    {
        foreach (MailCraft::getInstance()->channelRegistry->getAllowedChannels() as $handle => $channel) {
            foreach ($channel->validateGlobalSettings($this->getChannelSettings($handle)) as $field => $messages) {
                foreach ((array)$messages as $message) {
                    $this->addError("channelSettings.$handle.$field", $message);
                }
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            ['pluginName', 'string'],
            ['pluginName', 'default', 'value' => 'MailCraft'],
            ['useWysiwyg', 'boolean'],
            ['useWysiwyg', 'default', 'value' => true],
            ['showCcBcc', 'boolean'],
            ['showCcBcc', 'default', 'value' => false],
            ['sendInstantEmailsDirectly', 'boolean'],
            ['sendInstantEmailsDirectly', 'default', 'value' => false],
            ['allowedChannels', 'validateAllowedChannels', 'skipOnEmpty' => false],
            ['channelSettings', 'validateChannelSettings', 'skipOnEmpty' => false],
            ['statusChannels', 'validateStatusChannels', 'skipOnEmpty' => false],
            ['statusRetentionDays', 'integer', 'min' => 0],
            ['statusRetentionDays', 'default', 'value' => 30],
            ['privateMessageTemplate', 'safe'],
            ['allowFrontendSending', 'boolean'],
            ['frontendSendingPerHour', 'integer', 'min' => 0],
            ['frontendSendingPerHour', 'default', 'value' => 30],
            ['frontendSendingType', 'string', 'max' => 100],
        ];
    }
}