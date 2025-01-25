<?php

namespace frontendservices\mailcraft\models;

use craft\base\Model;

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
     * @var bool Wheter to show CC and BCC fields in the email template editor
     */
    public bool $showCcBcc = false;

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
        ];
    }
}