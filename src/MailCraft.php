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
use frontendservices\mailcraft\events\providers\EntryUpdateEventProvider;
use frontendservices\mailcraft\models\Settings;
use frontendservices\mailcraft\services\ConditionService;
use frontendservices\mailcraft\services\EmailService;
use frontendservices\mailcraft\services\EventRegistry;
use frontendservices\mailcraft\variables\MailCraftVariable;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Event;
use yii\base\Exception;
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
 */
class MailCraft extends Plugin
{
    public string $schemaVersion = '1.0.0';
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
        if ($emailService) {
            $emailService->init();
        }
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
            $registry->registerProvider(new EntryUpdateEventProvider());
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