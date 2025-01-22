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
use frontendservices\mailcraft\models\Settings;
use frontendservices\mailcraft\services\EmailService;
use frontendservices\mailcraft\variables\MailCraftVariable;
use yii\base\Event;

/**
 * MailCraft plugin
 *
 * @method static MailCraft getInstance()
 * @method Settings getSettings()
 * @author frontend.services <mato@frontend.services>
 * @copyright frontend.services
 * @license https://craftcms.github.io/license/ Craft License
 * @property-read EmailService $emailService
 */
class MailCraft extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    // Whether the plugin has editions
    public bool $hasEditions = true;

    // Edition constants
    public const EDITION_STANDARD = 'standard';
    public const EDITION_PRO = 'pro';

    /**
     * @inheritdoc
     */
    public static function editions(): array
    {
        return [
            self::EDITION_STANDARD,
            self::EDITION_PRO,
        ];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'emailService' => ['class' => 'frontendservices\mailcraft\services\EmailService'],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Register components
        $this->setComponents([
            'emailService' => EmailService::class,
        ]);

        $emailService = $this->get('emailService');
        $emailService->init();

        // Register variable
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                $event->sender->set('mailCraft', MailCraftVariable::class);
            }
        );

        // Register event handlers
        $this->attachEventHandlers();

        // Defer potentially conflicting initializations
        Craft::$app->onInit(function() {
            $this->registerCpRoutes();
            $this->registerElementTypes();
            $this->registerPermissions();
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('mailcraft/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function attachEventHandlers(): void
    {
        // Register CP URLs
        // Event::on(
        //     UrlManager::class,
        //     UrlManager::EVENT_REGISTER_CP_URL_RULES,
        //     function(RegisterUrlRulesEvent $event) {
        //         $event->rules['mailcraft'] = 'mailcraft/email-templates/index';
        //         $event->rules['mailcraft/email-templates/new'] = 'mailcraft/email-templates/edit';
        //         $event->rules['mailcraft/email-templates/<templateId:\d+>'] = 'mailcraft/email-templates/edit';
        //     }
        // );
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
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
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = EmailTemplate::class;
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions['MailCraft'] = [
                    'mailcraft:viewEmailTemplates' => [
                        'label' => Craft::t('mailcraft', 'View email templates'),
                    ],
                    'mailcraft:manageEmailTemplates' => [
                        'label' => Craft::t('mailcraft', 'Manage email templates'),
                        'info' => Craft::t('mailcraft', 'Create, edit and delete email templates'),
                    ],
                ];
            }
        );
    }

    protected function cpNavIconPath(): ?string
    {
        return null; // Replace with path to your icon if you have one
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        // Use the custom plugin name from settings
        $item['label'] = $this->getSettings()->pluginName;

        return $item;
    }
}