<?php

namespace frontendservices\mailcraft\variables;

use Craft;
use craft\ckeditor\events\ModifyConfigEvent;
use craft\ckeditor\Field;
use craft\web\View;
use frontendservices\mailcraft\elements\EmailTemplate;
use frontendservices\mailcraft\MailCraft;
use frontendservices\mailcraft\events\TriggerEvents;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use yii\base\Event;

class MailCraftVariable
{
    /**
     * Get all available events
     */
    public function getAvailableEvents(): array
    {
        return TriggerEvents::getAvailableEvents();
    }

    /**
     * Get events but prepare them for optiongroups
     */
    public function getAvailableEventsForOptions(): array
    {
        $events = TriggerEvents::getAvailableEvents();

        $options = [];

        foreach ($events as $group => $event) {
            $options[] = ['optgroup' => $group];

            foreach ($event as $key => $details) {
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
        return TriggerEvents::getAvailableEventsList();
    }

    /**
     * Get array of available trigger events
     */
    public function getTriggerEvents(): array
    {
        return TriggerEvents::getAvailableEvents();
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
}