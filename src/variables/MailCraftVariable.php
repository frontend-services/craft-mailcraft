<?php

namespace frontendservices\mailcraft\variables;

use Craft;
use craft\helpers\App;
use craft\ckeditor\events\ModifyConfigEvent;
use craft\ckeditor\Field;
use craft\ckeditor\helpers\CkeditorConfig;
use craft\ckeditor\Plugin as CkeditorPlugin;
use craft\ckeditor\web\assets\BaseCkeditorPackageAsset;
use frontendservices\mailcraft\assetbundles\ckeditor\MailCraftCkeditorAsset;
use craft\web\View;
use frontendservices\mailcraft\MailCraft;
use frontendservices\mailcraft\services\EventRegistry;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use yii\base\Event;
use yii\base\Exception;
use yii\base\InvalidConfigException;

class MailCraftVariable
{
    /**
     * Get events and prepare them for optiongroups
     *
     * @throws InvalidConfigException
     */
    public function getAvailableEventsForOptions(): array
    {
        $module = Craft::$app->getModule('mailcraft');
        if (!$module) {
            return [];
        }

        /** @var $registry EventRegistry */
        $registry = $module->get('eventRegistry');
        if (!$registry) {
            return [];
        }

        $events = $registry->getAllEvents();
        $options = [
            [
                'label' => Craft::t('mailcraft', 'Select an event'),
                'value' => '',
            ],
        ];

        foreach ($events as $group => $groupEvents) {
            $options[] = ['optgroup' => $group];

            foreach ($groupEvents as $key => $details) {
                $options[] = [
                    'label' => $details['label'],
                    'value' => $key,
                ];
            }
        }

        return $options;
    }

    public function getAvailableEventsList(): array
    {
        /** @var EventRegistry $registry */
        $registry = MailCraft::getInstance()->get('eventRegistry');

        return $registry->getAvailableEventsList();
    }

    /**
     * Registers MailCraft's CKEditor package (Twig placeholder highlighting).
     *
     * Requires the CKEditor plugin 5.x (import-map based packages); older versions
     * simply render the editor without highlighting.
     *
     * - CKEditor 5.8+ accepts late registrations through `Plugin::registerCkeditorPackage()`
     *   and takes care of the import map, package config and asset bundle itself.
     * - CKEditor 5.0–5.7 only read packages during their own init(), which runs before
     *   MailCraft's, so there the import map entry, package config and bundle are
     *   registered by hand right before the body field is rendered.
     */
    private function registerCkeditorPackage(): void
    {
        if (
            !class_exists(BaseCkeditorPackageAsset::class)
            || !class_exists(CkeditorConfig::class)
            || !class_exists(CkeditorPlugin::class)
        ) {
            return; // CKEditor plugin missing or too old for third-party packages
        }

        $view = Craft::$app->getView();
        if (!method_exists($view, 'registerJsImport')) {
            return;
        }

        try {
            if (method_exists(CkeditorPlugin::class, 'registerCkeditorPackageBundles')) {
                // 5.8+
                CkeditorPlugin::registerCkeditorPackage(MailCraftCkeditorAsset::class, MailCraftCkeditorAsset::ENTRY);
                return;
            }

            // 5.0–5.7
            $bundle = $view->registerAssetBundle(MailCraftCkeditorAsset::class);
            if (!$bundle instanceof MailCraftCkeditorAsset) {
                return;
            }
            $url = $view->getAssetManager()->getAssetUrl($bundle, MailCraftCkeditorAsset::ENTRY, false);
            $view->registerJsImport($bundle->namespace, $url);
            $bundle->registerPackage();
        } catch (\Throwable $e) {
            Craft::warning('MailCraft: could not register CKEditor highlight package: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Template text editor html
     */
    public function templateEditor($emailTemplate): string
    {
        $handle = 'template';
        $name = Craft::t('mailcraft', 'Email body');
        $instructions = Craft::t('mailcraft', 'Write the email. Paste variables from the panel to insert dynamic content. Twig syntax works too.');
        $template = $emailTemplate->template;
        $pluginSettings = MailCraft::getInstance()->getSettings();

        if (Craft::$app->plugins->isPluginEnabled('ckeditor') && $pluginSettings->useWysiwyg) {
            // Old versions may have stored ckeditor settings as a non-array
            $ckeditorSettings = $pluginSettings->ckeditor ?? [];
            if (!is_array($ckeditorSettings)) {
                $ckeditorSettings = [];
            }

            // toolbar: JSON array string (new visual builder) or comma-separated string (old format)
            $toolbar = $ckeditorSettings['toolbar'] ?? null;
            if (is_string($toolbar)) {
                $decoded = json_decode($toolbar, true);
                $ckeditorSettings['toolbar'] = is_array($decoded)
                    ? $decoded
                    : array_values(array_filter(array_map('trim', explode(',', $toolbar))));
            } elseif (!is_array($toolbar)) {
                unset($ckeditorSettings['toolbar']); // let Field use its own default
            }

            // headingLevels: stored as strings from the form; Field expects integers 1-6
            // The checkboxSelectField hidden input may submit '' as first value — filter it out
            $headingLevels = $ckeditorSettings['headingLevels'] ?? null;
            if (is_array($headingLevels)) {
                $ckeditorSettings['headingLevels'] = array_values(array_unique(array_filter(
                    array_map('intval', $headingLevels),
                    fn($v) => $v >= 1 && $v <= 6
                )));
            } elseif (!is_null($headingLevels)) {
                unset($ckeditorSettings['headingLevels']); // let Field use its own default
            }

            // wordLimit/characterLimit: empty string from form must become null (Field type is ?int)
            foreach (['wordLimit', 'characterLimit'] as $key) {
                if (isset($ckeditorSettings[$key])) {
                    $ckeditorSettings[$key] = $ckeditorSettings[$key] !== '' ? (int)$ckeditorSettings[$key] : null;
                }
            }

            // Boolean settings: old versions may have stored as string "1"/"" etc.
            foreach (['showWordCount', 'showUnpermittedVolumes', 'showUnpermittedFiles', 'purifyHtml'] as $boolKey) {
                if (isset($ckeditorSettings[$boolKey])) {
                    $ckeditorSettings[$boolKey] = (bool)$ckeditorSettings[$boolKey];
                }
            }

            // entryTypes: old versions may have stored as "" — Field constructor handles [] fine
            if (isset($ckeditorSettings['entryTypes']) && !is_array($ckeditorSettings['entryTypes'])) {
                unset($ckeditorSettings['entryTypes']);
            }

            $field = new \craft\ckeditor\Field(
                array_merge(
                    [
                        'handle' => $handle,
                        'name' => $name,
                        'instructions' => $instructions,
                        'required' => true,
                    ],
                    $ckeditorSettings
                )
            );

            // Twig placeholder highlighting (editing view only, nothing stored)
            $this->registerCkeditorPackage();

            $ckEditor = $field->getInputHtml($template, null);

            // Destroy existing CKEditor instances before PJAX replaces the DOM.
            // Inline module scripts (no src) are NOT deduplicated by PJAX — without cleanup,
            // navigating away and back causes a second create("template", config) call on the
            // same element while the first editor is still alive → schema-cannot-register-item-twice.
            Craft::$app->getView()->registerJs(<<<JS
(function() {
    if (window.__mailcraft_cke_cleanup) return;
    window.__mailcraft_cke_cleanup = true;
    \$(document).on('pjax:beforeReplace', function() {
        document.querySelectorAll('.ck-editor__editable[contenteditable]').forEach(function(el) {
            if (el.ckeditorInstance) {
                el.ckeditorInstance.destroy().catch(function() {});
            }
        });
    });
})();
JS, \craft\web\View::POS_END);

            $twig = <<<EOD
                {% import "_includes/forms" as forms %}
                {{ forms.field({
                    label: name,
                    instructions: instructions,
                    errors: emailTemplate.getErrors('template'),
                    required: true,
                }, field) }}
EOD;
            try {
                return Craft::$app->getView()->renderString($twig, [
                    'field' => $ckEditor,
                    'name' => $name,
                    'instructions' => $instructions,
                    'emailTemplate' => $emailTemplate,
                ], View::TEMPLATE_MODE_CP);
            } catch (LoaderError|SyntaxError $e) {
                return $e->getMessage();
            }
        }

        // output twig rendered textarea
        $twig = <<<EOD
            {% import "_includes/forms" as forms %}
            {{ forms.textareaField({
                label: name,
                instructions: instructions,
                class: 'nicetext code',
                rows: 20,
                id: handle,
                name: handle,
                value: template,
                errors: emailTemplate.getErrors('template'),
                required: true,
            }) }}
EOD;

        try {
            return Craft::$app->getView()->renderString($twig, [
                'name' => $name,
                'handle' => $handle,
                'instructions' => $instructions,
                'template' => $template,
                'emailTemplate' => $emailTemplate,
            ], View::TEMPLATE_MODE_CP);
        } catch (LoaderError|SyntaxError $e) {
            return $e->getMessage();
        }
    }

    /**
     * Get global conditions
     */
    public function getGlobalConditions(): array
    {
        return MailCraft::getInstance()->conditionService->getAllConditions();
    }

    /**
     * Returns an HTML table with order items and totals formatted for an email
     *
     * @param \craft\commerce\elements\Order $order
     * @return string
     * @throws Exception
     */
    public function orderDetails(\craft\commerce\elements\Order $order): string
    {
        $view = Craft::$app->getView();
        $oldMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        try {
            $template = <<<EOT
<table style="width:100%; border-collapse:collapse; margin-bottom:20px;">
    <tr style="background-color:#f5f5f5;">
        <th style="text-align:left; padding:8px; border-bottom:1px solid #ddd;">{{ 'Item'|t('commerce') }}</th>
        <th style="text-align:center; padding:8px; border-bottom:1px solid #ddd;">{{ 'Qty'|t('commerce') }}</th>
        <th style="text-align:right; padding:8px; border-bottom:1px solid #ddd;">{{ 'Price'|t('commerce') }}</th>
    </tr>
    
    {% for lineItem in order.lineItems %}
    <tr>
        <td style="padding:8px; border-bottom:1px solid #eee;">{{ lineItem.description }}</td>
        <td style="padding:8px; text-align:center; border-bottom:1px solid #eee;">{{ lineItem.qty }}</td>
        <td style="padding:8px; text-align:right; border-bottom:1px solid #eee;">{{ lineItem.totalAsCurrency }}</td>
    </tr>
    {% endfor %}
    
    <tr>
        <td colspan="2" style="padding:8px; text-align:right;">{{ 'Subtotal'|t('commerce') }}:</td>
        <td style="padding:8px; text-align:right;">{{ order.itemSubtotalAsCurrency }}</td>
    </tr>
    
    {% if order.totalShippingCost > 0 %}
    <tr>
        <td colspan="2" style="padding:8px; text-align:right;">{{ 'Shipping'|t('commerce') }}:</td>
        <td style="padding:8px; text-align:right;">{{ order.totalShippingCostAsCurrency }}</td>
    </tr>
    {% endif %}
    
    {% if order.totalTax > 0 %}
    <tr>
        <td colspan="2" style="padding:8px; text-align:right;">{{ 'Tax'|t('commerce') }}:</td>
        <td style="padding:8px; text-align:right;">{{ order.totalTaxAsCurrency }}</td>
    </tr>
    {% endif %}
    
    {% if order.totalDiscount > 0 %}
    <tr>
        <td colspan="2" style="padding:8px; text-align:right;">{{ 'Discount'|t('commerce') }}:</td>
        <td style="padding:8px; text-align:right;">{{ order.totalDiscountAsCurrency }}</td>
    </tr>
    {% endif %}
    
    <tr>
        <td colspan="2" style="padding:8px; text-align:right; font-weight:bold; border-top:1px solid #ddd;">{{ 'Total'|t('commerce') }}:</td>
        <td style="padding:8px; text-align:right; font-weight:bold; border-top:1px solid #ddd;">{{ order.totalPriceAsCurrency }}</td>
    </tr>
</table>
EOT;

            return $view->renderString($template, [
                'order' => $order,
            ]);
        } catch (\Throwable $e) {
            Craft::error('Error rendering order details: ' . $e->getMessage(), __METHOD__);
            return 'Error rendering order details.';
        } finally {
            $view->setTemplateMode($oldMode);
        }
    }

    /**
     * Template variables documented by every event provider.
     * Used by the edit page to show which `{{ … }}` placeholders an event supports.
     */
    public function getEventTemplateVariables(): array
    {
        $out = [];
        foreach (MailCraft::getInstance()->eventRegistry->getProviders() as $id => $provider) {
            $out[$id] = $provider->getTemplateVariables();
        }
        return $out;
    }

    /**
     * Example emails from every event provider.
     */
    public function getTemplateExamples(): array
    {
        $out = [];
        foreach (MailCraft::getInstance()->eventRegistry->getProviders() as $id => $provider) {
            $out[$id] = $provider->getTemplateExample();
        }
        return $out;
    }

    /**
     * Human labels for every event.
     */
    public function getEventLabels(): array
    {
        $out = [];
        foreach (MailCraft::getInstance()->eventRegistry->getProviders() as $id => $provider) {
            $out[$id] = $provider->getEventDetails()['label'] ?? $id;
        }
        return $out;
    }

    /**
     * The system From address and name, with environment variables resolved.
     * Shown as placeholders so editors know what an empty From field falls back to.
     */
    public function getSystemMailDefaults(): array
    {
        $settings = App::mailSettings();
        return [
            'fromEmail' => (string)App::parseEnv($settings->fromEmail),
            'fromName' => (string)App::parseEnv($settings->fromName),
        ];
    }
}
