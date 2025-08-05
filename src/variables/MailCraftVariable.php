<?php

namespace frontendservices\mailcraft\variables;

use Craft;
use craft\ckeditor\events\ModifyConfigEvent;
use craft\ckeditor\Field;
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
            'label' => Craft::t('mailcraft', 'Select an event'),
            'value' => '',
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
     * Template text editor html
     */
    public function templateEditor($emailTemplate): string
    {
        $handle = 'template';
        $name = Craft::t('mailcraft', 'Template');
        $instructions = Craft::t('mailcraft', 'Email body template. Use Twig syntax for dynamic content.');
        $template = $emailTemplate->template;
        $pluginSettings = MailCraft::getInstance()->getSettings();

        if (Craft::$app->plugins->isPluginEnabled('ckeditor') && $pluginSettings->useWysiwyg) {
            $field = new \craft\ckeditor\Field(
                array_merge(
                    [
                        'handle' => $handle,
                        'name' => $name,
                        'instructions' => $instructions,
                        'required' => true,
                    ],
                    $pluginSettings->ckeditor ?? []
                )
            );

            $ckEditor = $field->getInputHtml($template, null, false);
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
}