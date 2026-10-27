<?php

namespace frontendservices\mailcraft\assetbundles\ckeditor;

use craft\ckeditor\web\assets\BaseCkeditorPackageAsset;
use craft\ckeditor\web\assets\ckeditor\CkeditorAsset;

/**
 * CKEditor 5 package that highlights Twig placeholders ({{ … }}, {% … %}, {# … #})
 */
class MailCraftCkeditorAsset extends BaseCkeditorPackageAsset
{
    public const ENTRY = 'js/index.js';

    public string $namespace = '@frontendservices/ckeditor5-mailcraft';

    public array $pluginNames = ['MailCraftTwigHighlight'];

    public array $toolbarItems = [];

    public function init(): void
    {
        $this->sourcePath = '@frontendservices/mailcraft/assetbundles/ckeditor/dist';

        $this->depends = [
            CkeditorAsset::class,
        ];

        $this->css = [
            'css/twig-highlight.css',
        ];

        parent::init();
    }
}
