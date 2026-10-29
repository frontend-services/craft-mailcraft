<?php
namespace frontendservices\mailcraft\events\providers;

use Craft;
use frontendservices\mailcraft\base\AbstractEventProvider;
use frontendservices\mailcraft\helpers\TemplateHelper;

/**
 * An event that never fires by itself: templates on it are sent from code
 * (`MailCraft::send()`), which supplies the variables.
 */
class ManualEventProvider extends AbstractEventProvider
{
    /**
     * @inheritDoc
     */
    public function getEventId(): string
    {
        return 'manual';
    }

    /**
     * @inheritDoc
     */
    public function getEventDetails(): array
    {
        return [
            'label' => Craft::t('mailcraft', 'Triggered from code'),
            'group' => Craft::t('mailcraft', 'Manual'),
        ];
    }

    /**
     * @inheritDoc
     */
    public function registerEventListener(callable $handler): void
    {
        // Nothing to listen to: templates on this event are only sent from code.
    }

    /**
     * @inheritDoc
     */
    public function getTemplateVariables(): array
    {
        $variables = TemplateHelper::getGeneralVariables();
        $variables['recipient'] = [
            'type' => 'User',
            'description' => 'The user the message is for',
            'fields' => TemplateHelper::userFields('recipient'),
        ];
        $variables['sender'] = [
            'type' => 'User',
            'description' => 'May be empty',
            'fields' => TemplateHelper::userFields('sender'),
        ];
        $variables['subject'] = [
            'type' => 'string',
            'description' => 'The subject passed from code',
            'sample' => 'Lorem ipsum dolor sit amet',
        ];
        $variables['content'] = [
            'type' => 'string',
            'description' => 'The content passed from code',
            'sample' => '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>',
        ];
        $variables['type'] = [
            'type' => 'string',
            'description' => 'The message type passed from code',
            'sample' => 'user',
        ];

        return $variables;
    }

    /**
     * @inheritDoc
     */
    public function getTemplateExample(): array
    {
        return [
            'id' => $this->getEventId(),
            'title' => 'Message from code',
            'subject' => '{{ subject }}',
            'template' => '{{ content|raw }}',
        ];
    }

    /**
     * @inheritDoc
     */
    public function getConditions(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function testConditions($template, $variables): bool
    {
        return $this->matchesExtraConditions($template, $variables);
    }
}
