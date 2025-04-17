<?php
namespace frontendservices\mailcraft;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use frontendservices\mailcraft\elements\EmailTemplate;
use frontendservices\mailcraft\events\providers\EntryCreateEventProvider;
use frontendservices\mailcraft\events\providers\EntryUpdateEventProvider;
use frontendservices\mailcraft\events\providers\SystemUpdatesAvailableEventProvider;
use frontendservices\mailcraft\events\providers\UserActivateEventProvider;
use frontendservices\mailcraft\events\providers\UserCreateEventProvider;
use frontendservices\mailcraft\events\providers\UserUpdateEventProvider;
use frontendservices\mailcraft\events\providers\UserVerifyEmailEventProvider;
use frontendservices\mailcraft\models\Settings;
use frontendservices\mailcraft\services\ConditionService;
use frontendservices\mailcraft\services\DataService;
use frontendservices\mailcraft\services\EmailService;
use frontendservices\mailcraft\services\EventRegistry;
use frontendservices\mailcraft\variables\MailCraftVariable;
use yii\base\Event;
use yii\base\InvalidConfigException;

/**
 * MailCraft plugin
 *
 * @method static MailCraft getInstance()
 * @method Settings getSettings()
 * @author frontend.services <mato@frontend.services>
 * @copyright frontend.services
 * @license https://craftcms.github.io/license/ Craft License
 * @property-read EmailService $emailService
 * @property-read null|array $cpNavItem
 * @property-read Settings $settings
 * @property-read ConditionService $conditionService
 * @property-read EventRegistry $eventRegistry
 * @property-read DataService $dataService
 */
class MailCraft extends Plugin
{
    public string $schemaVersion = '1.1.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;
    public bool $hasEditions = false;

    // Edition constants
    public const EDITION_STANDARD = 'standard';

    /**
     * @inheritdoc
     */
    public static function editions(): array
    {
        return [
            self::EDITION_STANDARD,
        ];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'emailService' => ['class' => 'frontendservices\mailcraft\services\EmailService'],
                'conditionService' => ['class' => 'frontendservices\mailcraft\services\ConditionService'],
                'dataService' => ['class' => 'frontendservices\mailcraft\services\DataService'],
            ],
        ];
    }

    /**
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        parent::init();

        Craft::$app->setModule('mailcraft', $this);

        // Register components
        $this->setComponents([
            'conditionService' => ConditionService::class,
            'emailService' => EmailService::class,
            'eventRegistry' => EventRegistry::class,
            'dataService' => DataService::class,
        ]);

        // Register variable
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                $event->sender->set('mailCraft', MailCraftVariable::class);
            }
        );

        // Defer potentially conflicting initializations
        Craft::$app->onInit(function() {
            $this->registerCpRoutes();
            $this->registerElementTypes();
            $this->registerPermissions();
        });

        $this->registerProviders();

        $emailService = $this->get('emailService');
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('mailcraft/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    /**
     * Register all event providers
     *
     * @throws InvalidConfigException
     */
    private function registerProviders(): void
    {
        /** @var EventRegistry $registry */
        $registry = $this->get('eventRegistry');

        if ($registry) {
            /** Entry events */
            $registry->registerProvider(new EntryUpdateEventProvider());
            $registry->registerProvider(new EntryCreateEventProvider());

            /** System events */
            $registry->registerProvider(new SystemUpdatesAvailableEventProvider());

            $craftEdition = version_compare(Craft::$app->getVersion(), '5.0.0', '<')
                ? Craft::Pro
                : craft\enums\CmsEdition::Pro;

            if (Craft::$app->edition === $craftEdition) {
                /** User events */
                $registry->registerProvider(new UserCreateEventProvider());
                $registry->registerProvider(new UserVerifyEmailEventProvider());
                $registry->registerProvider(new UserUpdateEventProvider());
                $registry->registerProvider(new UserActivateEventProvider());
            }

            if (Craft::$app->plugins->isPluginEnabled('commerce')) {
                /** Commerce events */
                $registry->registerProvider(new \frontendservices\mailcraft\events\providers\CommerceOrderStatusChangeEventProvider());
                $registry->registerProvider(new \frontendservices\mailcraft\events\providers\CommerceOrderCreatedEventProvider());
//                $registry->registerProvider(new \frontendservices\mailcraft\events\providers\CommerceOrderCompleteEventProvider());
//                $registry->registerProvider(new \frontendservices\mailcraft\events\providers\CommerceOrderRefundEventProvider());
//                $registry->registerProvider(new \frontendservices\mailcraft\events\providers\CommerceOrderPaidEventProvider());
//                $registry->registerProvider(new \frontendservices\mailcraft\events\providers\CommerceOrderRefundedEventProvider());
            }
        }
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['mailcraft'] = 'mailcraft/email-templates/index';
                $event->rules['mailcraft/email-templates/new'] = 'mailcraft/email-templates/new';
                $event->rules['mailcraft/email-templates/<templateId:\d+>'] = 'mailcraft/email-templates/edit';
            }
        );
    }

    private function registerElementTypes(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = EmailTemplate::class;
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    "heading" => "MailCraft",
                    "permissions" => [
                        'mailcraft:manageEmailTemplates' => [
                            'label' => Craft::t('mailcraft', 'Manage email templates'),
                            'info' => Craft::t('mailcraft', 'Create, edit and delete email templates'),
                        ],
                    ],
                ];
            }
        );
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        // Use the custom plugin name from settings
        $item['label'] = $this->getSettings()->pluginName;

        return $item;
    }
}
