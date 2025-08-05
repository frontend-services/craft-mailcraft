<?php

namespace frontendservices\mailcraft\services;

use Craft;
use yii\base\Component;
use craft\htmlfield\HtmlField;

/**
 * Ckeditor Service
 *
 * @property-read array $settingsVariables
 */
class CkeditorService extends HtmlField
{
    /**
     * Build variables for CKEditor settings twig
     */
    public function getSettingsVariables(): array
    {
        $volumeOptions = [];
        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            if ($volume->getFs()->hasUrls) {
                $volumeOptions[] = [
                    'label' => $volume->name,
                    'value' => $volume->uid,
                ];
            }
        }
        $variables['volumeOptions'] = $volumeOptions;

        $transformOptions = [];
        foreach (Craft::$app->getImageTransforms()->getAllTransforms() as $transform) {
            $transformOptions[] = [
                'label' => $transform->name,
                'value' => $transform->uid,
            ];
        }

        $variables['transformOptions'] = $transformOptions;
        $variables['defaultTransformOptions'] = array_merge([
            [
                'label' => Craft::t('ckeditor', 'No transform'),
                'value' => null,
            ],
        ], $transformOptions);

        $variables['purifierConfigOptions'] = $this->configOptions('purifierConfig');

        return $variables;
    }
}
