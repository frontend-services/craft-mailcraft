<?php
namespace frontendservices\mailcraft\events\providers;

use Craft;
use craft\events\UserEvent;
use craft\services\Users;
use frontendservices\mailcraft\base\AbstractEventProvider;
use frontendservices\mailcraft\helpers\TemplateHelper;
use frontendservices\mailcraft\MailCraft;
use yii\base\Event;

class UserActivateEventProvider extends AbstractEventProvider
{
    /**
     * @inheritDoc
     */
    public function getEventId(): string
    {
        return 'user.activate';
    }

    /**
     * @inheritDoc
     */
    public function getEventDetails(): array
    {
        return [
            'label' => 'When User is Activated',
            'group' => 'Users',
        ];
    }

    /**
     * @inheritDoc
     */
    public function registerEventListener(callable $handler): void
    {
        Event::on(
            Users::class,
            Users::EVENT_AFTER_ACTIVATE_USER,
            static function(UserEvent $event) use ($handler) {
                $user = $event->user;
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
            ],
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
            'title' => 'User Activation Notification',
            'subject' => 'User Activated: {{user.username}}',
            'template' => '<h1>User Activated</h1>
<p>The user "{{user.username}}" with email "{{user.email}}" has been activated.</p>
<p>View user details <a href="{{user.cpEditUrl}}">here</a>.</p>',
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

        if (isset($template->conditions) && $template->conditions) {
            $twig = Craft::$app->getView()->renderString("{{".$template->conditions."}}", [
                'user' => $user,
            ]);
            try {
                return Craft::$app->getView()->renderString($twig) === "1";
            } catch (\Throwable $e) {
                return false;
            }
        }

        return true;
    }
}