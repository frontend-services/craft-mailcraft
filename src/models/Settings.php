<?php

namespace frontendservices\mailcraft\models;

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
     * @var mixed|null CKEditor plugin instance if available
     */
    public mixed $ckeditor = null;

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
        ];
    }
}