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
            $field = new \craft\ckeditor\Field([
                'handle' => $handle,
                'name' => $name,
                'instructions' => $instructions,
                'required' => true,
            ]);
            Event::on(
                Field::class,
                Field::EVENT_MODIFY_CONFIG,
                static function(ModifyConfigEvent $event) {
                    $event->toolbar[] = 'sourceEditing';
                }
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
}