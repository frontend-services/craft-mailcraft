<?php
namespace frontendservices\mailcraft;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\ckeditor\helpers\CkeditorConfig;
use craft\ckeditor\web\assets\fieldsettings\FieldSettingsAsset;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\Controller;
use craft\web\UrlManager;
use frontendservices\mailcraft\elements\EmailTemplate;
use frontendservices\mailcraft\events\providers\AssetCreateEventProvider;
use frontendservices\mailcraft\events\providers\AssetDeleteEventProvider;
use frontendservices\mailcraft\events\providers\AssetUpdateEventProvider;
use frontendservices\mailcraft\events\providers\EntryCreateEventProvider;
use frontendservices\mailcraft\events\providers\EntryDeleteEventProvider;
use frontendservices\mailcraft\events\providers\EntryUpdateEventProvider;
use frontendservices\mailcraft\events\providers\ManualEventProvider;
use frontendservices\mailcraft\events\providers\SystemQueueFailEventProvider;
use frontendservices\mailcraft\events\providers\SystemUpdatesAvailableEventProvider;
use frontendservices\mailcraft\events\providers\UserActivateEventProvider;
use frontendservices\mailcraft\events\providers\UserCreateEventProvider;
use frontendservices\mailcraft\events\providers\UserDeleteEventProvider;
use frontendservices\mailcraft\events\providers\UserUpdateEventProvider;
use frontendservices\mailcraft\events\providers\UserVerifyEmailEventProvider;
use frontendservices\mailcraft\models\Settings;
use frontendservices\mailcraft\services\ChannelRegistry;
use frontendservices\mailcraft\services\CkeditorService;
use frontendservices\mailcraft\services\ConditionService;
use frontendservices\mailcraft\services\DataService;
use frontendservices\mailcraft\services\EmailService;
use frontendservices\mailcraft\services\EventRegistry;
use frontendservices\mailcraft\services\PrivateMessageService;
use frontendservices\mailcraft\services\StatusService;
use frontendservices\mailcraft\variables\MailCraftVariable;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\web\Response;

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
 * @property-read ChannelRegistry $channelRegistry
 * @property-read StatusService $statusService
 * @property-read PrivateMessageService $privateMessages
 */
class MailCraft extends Plugin
{
    public string $schemaVersion = '1.4.0';
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

    /**
     * Sends a template from code (by element, id or handle) with the given Twig variables.
     */
    public static function send(int|string|EmailTemplate $template, array $variables = [], int $delay = 0): bool
    {
        return static::getInstance()->emailService->send($template, $variables, $delay);
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
            'channelRegistry' => ChannelRegistry::class,
            'statusService' => StatusService::class,
            'privateMessages' => PrivateMessageService::class,
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

        Event::on(\craft\services\Gc::class, \craft\services\Gc::EVENT_RUN, function() {
            $this->statusService->purge();
            $this->privateMessages->purgeExpired();
        });

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
    public function getSettingsResponse(): mixed
    {
        return $this->renderSettingsPage(false);
    }

    /**
     * Craft 5.6+ calls this instead of getSettingsResponse() when admin changes are not allowed.
     */
    public function getReadOnlySettingsResponse(): mixed
    {
        return $this->renderSettingsPage(true);
    }

    /**
     * @inheritdoc
     *
     * Not used by Craft once getSettingsResponse() is overridden; kept so anything else that
     * asks for the settings HTML still gets every field, one group after the other.
     */
    protected function settingsHtml(): ?string
    {
        return implode("\n<hr>\n", array_column($this->renderSettingsPanes(false), 'html'));
    }

    /**
     * The settings page: one tab per group of settings. Each pane is rendered and namespaced
     * under `settings` on its own; the tab list and the pane ids stay outside that namespace so
     * Craft's tab switcher finds them.
     */
    private function renderSettingsPage(bool $readOnly): Response
    {
        /** @var Controller $controller */
        $controller = Craft::$app->controller;

        return $controller->renderTemplate('mailcraft/settings/index.twig', [
            'plugin' => $this,
            'panes' => $this->renderSettingsPanes($readOnly),
            'readOnly' => $readOnly,
            'contentNotice' => $readOnly && method_exists(Cp::class, 'readOnlyNoticeHtml') ? Cp::readOnlyNoticeHtml() : '',
        ]);
    }

    /**
     * Renders the settings groups, in tab order.
     *
     * @return array<string, array{label: string, html: string, hasErrors: bool}> keyed by tab id
     */
    private function renderSettingsPanes(bool $readOnly): array
    {
        $settings = $this->getSettings();
        $view = Craft::$app->getView();
        $variables = [
            'plugin' => $this,
            'settings' => $settings,
            'channels' => $this->channelRegistry->getChannels(),
        ];

        $panes = [
            'general' => [
                'label' => Craft::t('mailcraft', 'General'),
                'template' => '_general',
                'attributes' => ['pluginName', 'useWysiwyg', 'showCcBcc', 'sendInstantEmailsDirectly'],
            ],
            'channels' => [
                'label' => Craft::t('mailcraft', 'Channels'),
                'template' => '_channels',
                'attributes' => ['allowedChannels', 'channelSettings', 'statusChannels', 'statusRetentionDays'],
            ],
        ];

        if (in_array('*', $settings->allowedChannels, true) || in_array('privateMessage', $settings->allowedChannels, true)) {
            $panes['private-messages'] = [
                'label' => Craft::t('mailcraft', 'Private messages'),
                'template' => '_private-messages',
                'attributes' => ['privateMessageTypes', 'privateMessageTemplate', 'allowFrontendSending', 'frontendSendingPerHour', 'frontendSendingType'],
            ];
        }

        if ($settings->useWysiwyg && Craft::$app->plugins->isPluginInstalled('ckeditor')) {
            $bundle = $view->registerAssetBundle(FieldSettingsAsset::class);

            $variables = array_merge($variables, $this->get('ckeditor')->getSettingsVariables() ?? []);
            $variables['toolbarItems'] = CkeditorConfig::normalizeToolbarItems(CkeditorConfig::$toolbarItems);
            $variables['plugins'] = CkeditorConfig::getAllPlugins();
            $variables['importStatements'] = CkeditorConfig::getImportStatements();
            $variables['baseIconsUrl'] = "$bundle->baseUrl/images";

            // Normalize ckeditor settings — old versions may have stored unexpected types
            $ckeditorSettings = $settings->ckeditor ?? [];
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

            $settings->ckeditor = $ckeditorSettings;

            $panes['editor'] = [
                'label' => Craft::t('mailcraft', 'Editor'),
                'template' => '_editor',
                'attributes' => ['ckeditor'],
            ];
        }

        // an error key is the attribute name, or `attribute.sub.key` for nested settings
        $erroredAttributes = [];
        foreach (array_keys($settings->getErrors()) as $key) {
            $erroredAttributes[explode('.', (string)$key, 2)[0]] = true;
        }

        $rendered = [];
        foreach ($panes as $id => $pane) {
            $render = static fn() => $view->renderTemplate('mailcraft/settings/' . $pane['template'], $variables);
            $rendered[$id] = [
                'label' => $pane['label'],
                'html' => $view->namespaceInputs(static function() use ($render, $readOnly) {
                    return $readOnly ? (string)Html::disableInputs($render) : (string)$render();
                }, 'settings'),
                'hasErrors' => array_intersect($pane['attributes'], array_keys($erroredAttributes)) !== [],
            ];
        }

        return $rendered;
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
            /** Sent from code only; never fires by itself */
            $registry->registerProvider(new ManualEventProvider());

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
                $event->rules['mailcraft/status'] = 'mailcraft/status/index';
                $event->rules['mailcraft/status/<id:\d+>'] = 'mailcraft/status/view';
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
                        'mailcraft:viewStatus' => [
                            'label' => Craft::t('mailcraft', 'View the Status page'),
                            'info' => Craft::t('mailcraft', 'Includes the full contents of every recorded message'),
                            'nested' => [
                                'mailcraft:manageStatus' => [
                                    'label' => Craft::t('mailcraft', 'Resend and delete statuses'),
                                ],
                            ],
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

        $user = Craft::$app->getUser();
        $canTemplates = $user->checkPermission('mailcraft:manageEmailTemplates');
        // hasRows() swallows errors (e.g. the table is missing before migrations run)
        $canStatus = $user->checkPermission('mailcraft:viewStatus')
            && ($this->getSettings()->statusChannels !== [] || $this->statusService->hasRows());

        if ($canStatus) {
            $subnav = [];
            if ($canTemplates) {
                $subnav['templates'] = ['label' => Craft::t('mailcraft', 'Email Templates'), 'url' => 'mailcraft'];
            }
            $subnav['status'] = ['label' => Craft::t('mailcraft', 'Status'), 'url' => 'mailcraft/status'];
            // Craft 5 renders any non-empty subnav, so only add one when there is a choice
            if (count($subnav) > 1) {
                $item['subnav'] = $subnav;
            }
            if (!$canTemplates) {
                $item['url'] = 'mailcraft/status';
            }
        }

        return $item;
    }
}
