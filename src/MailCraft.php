<?php
namespace frontendservices\mailcraft;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\ckeditor\helpers\CkeditorConfig;
use craft\ckeditor\web\assets\fieldsettings\FieldSettingsAsset;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use frontendservices\mailcraft\elements\EmailTemplate;
use frontendservices\mailcraft\events\providers\AssetCreateEventProvider;
use frontendservices\mailcraft\events\providers\AssetDeleteEventProvider;
use frontendservices\mailcraft\events\providers\AssetUpdateEventProvider;
use frontendservices\mailcraft\events\providers\EntryCreateEventProvider;
use frontendservices\mailcraft\events\providers\EntryDeleteEventProvider;
use frontendservices\mailcraft\events\providers\EntryUpdateEventProvider;
use frontendservices\mailcraft\events\providers\SystemQueueFailEventProvider;
use frontendservices\mailcraft\events\providers\SystemUpdatesAvailableEventProvider;
use frontendservices\mailcraft\events\providers\UserActivateEventProvider;
use frontendservices\mailcraft\events\providers\UserCreateEventProvider;
use frontendservices\mailcraft\events\providers\UserDeleteEventProvider;
use frontendservices\mailcraft\events\providers\UserUpdateEventProvider;
use frontendservices\mailcraft\events\providers\UserVerifyEmailEventProvider;
use frontendservices\mailcraft\models\Settings;
use frontendservices\mailcraft\services\CkeditorService;
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
 * @property-read CkeditorService $ckeditor
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
            'ckeditor' => CkeditorService::class,
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
        $settings = $this->getSettings();
        $variables = [
            'plugin' => $this,
            'settings' => $settings,
        ];

        if (\Craft::$app->plugins->isPluginInstalled('ckeditor')) {
            $view = Craft::$app->getView();
            $bundle = $view->registerAssetBundle(FieldSettingsAsset::class);

            $variables = array_merge($variables, $this->get('ckeditor')->getSettingsVariables() ?? []);
            $variables['toolbarItems'] = CkeditorConfig::normalizeToolbarItems(CkeditorConfig::$toolbarItems);
            $variables['plugins'] = CkeditorConfig::getAllPlugins();
            $variables['importStatements'] = CkeditorConfig::getImportStatements();
            // namespaceInputId() pre-computes the ID after Craft's namespace is applied.
            // The HTML div must use a plain hardcoded id (no {{ toolbarBuilderId }}) so
            // Craft namespaces it exactly once — matching the pre-computed JS value.
            $variables['toolbarBuilderId'] = $view->namespaceInputId('mailcraft-toolbar-builder');
            $variables['baseIconsUrl'] = "$bundle->baseUrl/images";

            // Normalize ckeditor settings — old versions may have stored unexpected types
            $ckeditorSettings = $variables['settings']->ckeditor ?? [];
            if (!is_array($ckeditorSettings)) {
                $ckeditorSettings = [];
            }

            // Normalize toolbar to array for the visual builder.
            // Old format: comma-separated string. New format: JSON array string. May also be null/missing.
            $toolbar = $ckeditorSettings['toolbar'] ?? null;
            if (is_string($toolbar)) {
                $decoded = json_decode($toolbar, true);
                $toolbar = is_array($decoded)
                    ? $decoded
                    : array_values(array_filter(array_map('trim', explode(',', $toolbar))));
            }
            $ckeditorSettings['toolbar'] = is_array($toolbar) && count($toolbar)
                ? $toolbar
                : ['heading', '|', 'bold', 'italic', 'link'];

            // Pass toolbar as a pre-encoded JSON string so the template doesn't need
            // to call json_encode (avoids any Twig encoding edge cases).
            $variables['toolbarJson'] = json_encode($ckeditorSettings['toolbar']);

            // entryTypes are stored as UIDs — convert for display in entryTypeSelectField.
            // Old versions may have stored this as "" or null.
            $entryTypes = $ckeditorSettings['entryTypes'] ?? [];
            if (!is_array($entryTypes)) {
                $entryTypes = [];
            }
            $ckeditorSettings['entryTypes'] = array_values(array_filter(array_map(
                static function($entryType) {
                    if (is_object($entryType)) {
                        return $entryType;
                    }
                    return Craft::$app->getEntries()->getEntryType($entryType);
                },
                $entryTypes
            )));

            $variables['settings']->ckeditor = $ckeditorSettings;
        }

        return Craft::$app->view->renderTemplate('mailcraft/_settings.twig', $variables);
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
            $registry->registerProvider(new EntryDeleteEventProvider());
            $registry->registerProvider(new AssetCreateEventProvider());
            $registry->registerProvider(new AssetUpdateEventProvider());
            $registry->registerProvider(new AssetDeleteEventProvider());

            /** System events */
            $registry->registerProvider(new SystemUpdatesAvailableEventProvider());
            $registry->registerProvider(new SystemQueueFailEventProvider());

            $craftEdition = version_compare(Craft::$app->getVersion(), '5.0.0', '<')
                ? Craft::Pro
                : craft\enums\CmsEdition::Pro;

            if (Craft::$app->edition === $craftEdition) {
                /** User events */
                $registry->registerProvider(new UserCreateEventProvider());
                $registry->registerProvider(new UserVerifyEmailEventProvider());
                $registry->registerProvider(new UserUpdateEventProvider());
                $registry->registerProvider(new UserActivateEventProvider());
                $registry->registerProvider(new UserDeleteEventProvider());
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
