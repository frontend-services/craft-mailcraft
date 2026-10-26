<?php
namespace frontendservices\mailcraft\events\providers;

use Craft;
use craft\base\Element;
use frontendservices\mailcraft\base\AbstractEventProvider;
use craft\elements\User;
use frontendservices\mailcraft\helpers\TemplateHelper;
use frontendservices\mailcraft\MailCraft;
use yii\base\Event;

class UserDeleteEventProvider extends AbstractEventProvider
{
    /**
     * @inheritDoc
     */
    public function getEventId(): string
    {
        return 'user.delete';
    }

    /**
     * @inheritDoc
     */
    public function getEventDetails(): array
    {
        return [
            'label' => 'User is Deleted',
            'group' => 'Users',
        ];
    }

    /**
     * @inheritDoc
     */
    public function registerEventListener(callable $handler): void
    {
        Event::on(
            User::class,
            Element::EVENT_AFTER_DELETE,
            static function(Event $event) use ($handler) {
                /** @var User $user */
                $user = $event->sender;
                $handler(['user' => $user]);
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function getTemplateVariables(): array
    {
        $variables = TemplateHelper::getGeneralVariables();
        $variables['user'] = [
            'type' => 'User',
            'description' => 'User model',
            'fields' => [
                'username' => 'Username',
                'email' => 'Email address',
                'firstName' => 'First name',
                'lastName' => 'Last name',
            ]
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
            'title' => 'User Deleted Notification',
            'subject' => 'User Deleted: {{user.username}}',
            'template' => '<h1>User Deleted</h1>
<p>The user "{{user.username}}" with email "{{user.email}}" has been deleted.</p>',
        ];
    }

    /**
     * @inheritDoc
     */
    public function getConditions(): array
    {
        return [
            'condition1' => [
                'operand' => 'user.isInGroup(condition)',
                'name' => Craft::t('mailcraft', 'User Group'),
                'options' => MailCraft::getInstance()->conditionService->getUserGroups(),
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function testConditions($template, $variables): bool
    {
        $user = $variables['user'] ?? null;
        if (!$user) {
            return false;
        }

        if (isset($template->condition1) && $template->condition1 && !$user->isInGroup($template->condition1)) {
            return false;
        }

        return $this->matchesExtraConditions($template, $variables);
    }
}
