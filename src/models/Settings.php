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
     * @var mixed|null CKEditor plugin instance if available
     */
    public mixed $ckeditor = null;

    /**
     * @inheritdoc
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        if (is_array($values) && array_key_exists('allowedChannels', $values)) {
            $values['allowedChannels'] = $this->normalizeAllowedChannels($values['allowedChannels']);
        }

        if (is_array($values) && array_key_exists('channelSettings', $values) && !is_array($values['channelSettings'])) {
            $values['channelSettings'] = [];
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
     * Accepts `'*'` (every registered channel, resolved lazily by the registry because the
     * registry does not exist yet while config-file settings are applied), a single handle,
     * or a list that may contain the empty value Craft's checkbox select posts, and returns
     * a clean list of handles.
     */
    private function normalizeAllowedChannels(mixed $value): array
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
            // skipOnEmpty would silently accept an empty list, which is exactly the state to reject
            ['allowedChannels', 'validateAllowedChannels', 'skipOnEmpty' => false],
            ['channelSettings', 'validateChannelSettings', 'skipOnEmpty' => false],
        ];
    }
}