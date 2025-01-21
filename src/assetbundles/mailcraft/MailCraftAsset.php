<?php

namespace frontendservices\mailcraft\assetbundles\mailcraft;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class MailCraftAsset extends AssetBundle
{
    public function init()
    {
        $this->sourcePath = "@frontendservices/mailcraft/assetbundles/mailcraft/dist";

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'js/mailcraft.js',
        ];

        $this->css = [
            'css/mailcraft.css',
        ];

        parent::init();
    }
}