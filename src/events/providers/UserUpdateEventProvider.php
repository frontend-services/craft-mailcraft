<?php
namespace frontendservices\mailcraft\events\providers;

use Craft;
use craft\elements\User;
use frontendservices\mailcraft\base\AbstractEventProvider;
use frontendservices\mailcraft\helpers\TemplateHelper;
use frontendservices\mailcraft\MailCraft;
use yii\base\Event;
use yii\base\ModelEvent;

class UserUpdateEventProvider extends AbstractEventProvider
{
    /**
     * @inheritDoc
     */
    public function getEventId(): string
    {
        return 'user.update';
    }

    /**
     * @inheritDoc
     */
    public function getEventDetails(): array
    {
        return [
            'label' => 'User is Updated',
            'group' => 'Users',
        ];
    }

    /**
     * @inheritDoc
     */
    public function registerEventListener(callable $handler): void
    {
        $captured = [];
        $attributeLabels = [
            'email' => 'Email',
            'username' => 'Username',
            'firstName' => 'First name',
            'lastName' => 'Last name',
            'fullName' => 'Full name',
            'photoId' => 'Photo',
            'admin' => 'Admin',
            'active' => 'Status',
            'pending' => 'Status',
            'suspended' => 'Status',
            'locked' => 'Locked',
            'newPassword' => 'Password',
            'password' => 'Password',
            'unverifiedEmail' => 'Email',
            'affiliatedSiteId' => 'Affiliated site',
        ];

        $ignoredAttributes = [
            'uid', 'dateUpdated', 'lastLoginDate', 'lastLoginAttemptIp', 'invalidLoginCount',
            'lastInvalidLoginAt', 'lockoutDate', 'verificationCode', 'verificationCodeIssuedDate',
            'lastPasswordChangeDate', 'passwordResetRequired', 'hasDashboard', 'authError', 'inheritorOnDelete',
        ];

        // Dirty attributes/fields are only known before the save, so capture them here and hand them over once EVENT_AFTER_SAVE confirms the save went through.
        Event::on(
            User::class,
            User::EVENT_BEFORE_SAVE,
            static function(ModelEvent $event) use (&$captured, $attributeLabels, $ignoredAttributes) {
                /** @var User $user */
                $user = $event->sender;
                if (!$user->id || $user->firstSave || $user->propagating || $user->resaving || $user->getIsDraft()) {
                    return;
                }

                $changedLabels = [];
                foreach ($user->getDirtyAttributes() as $attr) {
                    if (in_array($attr, $ignoredAttributes, true)) {
                        continue;
                    }
                    $label = $attributeLabels[$attr] ?? ucfirst($attr);
                    $changedLabels[$label] = true;
                }
                if ($user->newPassword !== null) {
                    $changedLabels['Password'] = true;
                }
                foreach ($user->getDirtyFields() as $fieldHandle) {
                    $field = Craft::$app->getFields()->getFieldByHandle($fieldHandle);
                    $label = $field ? $field->name : ucfirst($fieldHandle);
                    $changedLabels[$label] = true;
                }

                $captured[$user->id] = array_keys($changedLabels);
            }
        );

        Event::on(
            User::class,
            User::EVENT_AFTER_SAVE,
            static function(ModelEvent $event) use ($handler, &$captured) {
                /** @var User $user */
                $user = $event->sender;
                $changedLabels = $captured[$user->id] ?? [];
                unset($captured[$user->id]);

                if (
                    !$user->getIsDraft() &&
                    !$user->firstSave &&
                    !$user->propagating
                ) {
                    $handler([
                        'user' => $user,
                        'changedFields' => $changedLabels,
                        'changedFieldsList' => implode(', ', $changedLabels),
                    ]);
                }
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
            'description' => 'The user that was updated',
            'fields' => TemplateHelper::userFields(),
        ];
        $variables += TemplateHelper::changedFieldsVariables();
        return $variables;
    }

    /**
     * @inheritDoc
     */
    public function getTemplateExample(): array
    {
        return [
            'id' => $this->getEventId(),
            'title' => 'User Update Notification',
            'subject' => 'User Updated: {{user.username}}',
            'template' => '<h1>User Profile Updated</h1>
<p>The user "{{user.username}}" with email "{{user.email}}" has been updated.</p>
{% if changedFieldsList %}<p><strong>Changed:</strong> {{changedFieldsList}}</p>{% endif %}
<p>View user details <a href="{{user.cpEditUrl}}">here</a>.',
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