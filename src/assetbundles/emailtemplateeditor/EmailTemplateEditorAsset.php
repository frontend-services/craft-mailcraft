<?php

namespace frontendservices\mailcraft\assetbundles\emailtemplateeditor;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class EmailTemplateEditorAsset extends AssetBundle
{
    public function init()
    {
        $this->sourcePath = "@frontendservices/mailcraft/assetbundles/emailtemplateeditor/dist";

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'js/editor.js',
        ];

        parent::init();
    }
}