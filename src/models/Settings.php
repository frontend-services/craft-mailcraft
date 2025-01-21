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
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            ['pluginName', 'string'],
            ['pluginName', 'default', 'value' => 'MailCraft'],
        ];
    }
}