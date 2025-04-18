<?php
namespace frontendservices\mailcraft\events\providers;

use Craft;
use frontendservices\mailcraft\MailCraft;
use yii\base\Application;
use frontendservices\mailcraft\base\AbstractEventProvider;
use frontendservices\mailcraft\helpers\TemplateHelper;
use yii\base\Event;

class SystemUpdatesAvailableEventProvider extends AbstractEventProvider
{
    public const EVENT_NEW_UPDATES = 'newUpdates';
    public const EVENT_NEW_CRITICAL_UPDATES = 'newCriticalUpdates';
    private const CACHE_KEY = 'mailcraft_updates_check';
    private const CACHE_DURATION = 30 * 60; // 30 minutes

    /**
     * @inheritDoc
     */
    public function getEventId(): string
    {
        return 'system.updates.available';
    }

    /**
     * @inheritDoc
     */
    public function getEventDetails(): array
    {
        return [
            'label' => 'Updates Available',
            'group' => 'System',
        ];
    }

    /**
     * @inheritDoc
     */
    public function registerEventListener(callable $handler): void
    {
        Event::on(
            self::class,
            self::EVENT_NEW_UPDATES,
            static function(Event $event) use ($handler) {
                $handler($event->sender);
            }
        );

        Event::on(
            self::class,
            self::EVENT_NEW_CRITICAL_UPDATES,
            static function(Event $event) use ($handler) {
                $handler($event->sender);
            }
        );

        Event::on(
            Application::class,
            Application::EVENT_AFTER_REQUEST,
            [$this, 'checkForUpdates']
        );
    }

    /**
     * Checks for updates and triggers the event if updates are available
     *
     * @return void
     */
    public function checkForUpdates(): void
    {
        $updates = Craft::$app->getCache()->getOrSet(self::CACHE_KEY . MailCraft::getInstance()->getVersion(), function() {
            // Only proceed if update info is already cached by Craft
            if (!Craft::$app->getUpdates()->getIsUpdateInfoCached()) {
                // Return false to not cache the output (will check again on next request)
                return false;
            }

            // Get available updates
            $updates = [
                'cms' => Craft::$app->getUpdates()->getUpdates()->cms,
                'plugins' => Craft::$app->getUpdates()->getUpdates()->plugins,
                'hasCriticalUpdates' => Craft::$app->getUpdates()->getIsCriticalUpdateAvailable(),
                'hasUpdates' => Craft::$app->getUpdates()->getTotalAvailableUpdates() > 0,
                'total' => Craft::$app->getUpdates()->getTotalAvailableUpdates(),
            ];

            return $this->formatUpdatesData($updates);
        }, self::CACHE_DURATION);

        // If updates are available, trigger the event
        if (!empty($updates)) {
            $previousUpdates = MailCraft::getInstance()->dataService->get('updates', [
                'totalUpdates' => 0,
                'totalCriticalUpdates' => 0
            ]);

            $hasNewUpdates = ($updates['totalUpdates'] ?? 0) > ($previousUpdates['totalUpdates'] ?? 0);
            $hasNewCriticalUpdates = ($updates['totalCriticalUpdates'] ?? 0) > ($previousUpdates['totalCriticalUpdates'] ?? 0);

            if ($hasNewCriticalUpdates) {
                Craft::info('New critical updates available: ' . json_encode($updates), 'mailcraft');
                Event::trigger(self::class, self::EVENT_NEW_CRITICAL_UPDATES, new Event([
                    'sender' => ['updates' => $updates, 'level' => self::EVENT_NEW_CRITICAL_UPDATES]
                ]));
            } else if ($hasNewUpdates) {
                // Only trigger regular updates event if there are no critical updates
                Craft::info('New updates available: ' . json_encode($updates), 'mailcraft');
                Event::trigger(self::class, self::EVENT_NEW_UPDATES, new Event([
                    'sender' => ['updates' => $updates, 'level' => self::EVENT_NEW_UPDATES]
                ]));
            }

            if ($updates !== $previousUpdates) {
                MailCraft::getInstance()->dataService->set('updates', $updates);
            }
        }
    }

    /**
     * Format the updates data for template variables
     *
     * @param array $updates The updates data
     * @return array Formatted updates
     */
    private function formatUpdatesData(array $updates): array
    {
        $result = [
            'hasUpdates' => $updates['hasUpdates'] ?? false,
            'hasCriticalUpdates' => $updates['hasCriticalUpdates'] ?? false,
            'totalUpdates' => $updates['total'] ?? 0,
            'cms' => [],
            'plugins' => [],
        ];

        // CMS updates
        if (!empty($updates['cms'])) {
            $result['cms'] = [
                'current' => Craft::$app->getVersion(),
                'latest' => $updates['cms']["releases"][0]["version"] ?? null,
                'releaseDate' => $updates['cms']["releases"][0]["date"] ?? null ? $updates['cms']["releases"][0]["date"]->format('Y-m-d H:i:s') : null,
                'isCritical' => $updates['cms']->getHasCritical(),
            ];
        }

        // Plugin updates
        foreach ($updates['plugins'] as $handle => $plugin) {
            if (!empty($plugin)) {
                $result['plugins'][$handle] = [
                    'name' => $plugin["packageName"],
                    'current' => Craft::$app->getPlugins()->getPlugin($handle)->getVersion(),
                    'latest' => $plugin["releases"][0]["version"] ?? null,
                    'releaseDate' => $plugin["releases"][0]["date"] ?? null ? $plugin["releases"][0]["date"]->format('Y-m-d H:i:s') : null,
                    'isCritical' => $plugin->getHasCritical(),
                ];
            }
        }
        $result['totalCriticalUpdates'] = 0;
        foreach ($result['plugins'] as $plugin) {
            if ($plugin['isCritical']) {
                $result['totalCriticalUpdates']++;
            }
        }
        $result['totalCriticalUpdates'] += $result['cms']['isCritical'] ? 1 : 0;
        $result['total'] = $result['totalUpdates'] + $result['totalCriticalUpdates'];

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getTemplateVariables(): array
    {
        $variables = TemplateHelper::getGeneralVariables();
        $variables['updates'] = [
            'type' => 'object',
            'description' => 'Information about available updates',
            'fields' => [
                'hasUpdates' => 'Boolean indicating if updates are available',
                'hasCriticalUpdates' => 'Boolean indicating if critical updates are available',
                'totalUpdates' => 'Total number of available updates',
                'totalCriticalUpdates' => 'Total number of critical updates',
                'cms' => [
                    'current' => 'Current Craft CMS version',
                    'latest' => 'Latest available Craft CMS version',
                    'releaseDate' => 'Release date of the latest version',
                    'isCritical' => 'Boolean indicating if this is a critical update'
                ],
                'plugins' => 'Array of plugin updates with details (name, current, latest, releaseDate, isCritical)'
            ]
        ];

        $variables['level'] = [
            'type' => 'string',
            'description' => 'The event level (newUpdates or newCriticalUpdates)'
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
            'title' => 'Updates Available Notification',
            'subject' => '{{ updates.total }} Update(s) Available for {{ siteName }}',
            'template' => '<h1>Updates Available for {{ siteName }}</h1>

<p>There are {{ updates.total }} update(s) available for your website.</p>

{% if updates.hasCriticalUpdates %}
<p><strong>⚠️ Critical updates are available! Please update as soon as possible.</strong></p>
{% endif %}

{% if updates.cms %}
<h2>Craft CMS Update</h2>
<p>Current version: {{ updates.cms.current }}<br>
Latest version: {{ updates.cms.latest }}<br>
Released: {{ updates.cms.releaseDate | date("F j, Y") }}</p>
{% endif %}

{% if updates.plugins | length > 0 %}
<h2>Plugin Updates</h2>
<ul>
{% for handle, plugin in updates.plugins %}
    <li>
        <strong>{{ plugin.name }}</strong><br>
        Current: {{ plugin.current }} → Latest: {{ plugin.latest }}<br>
        Released: {{ plugin.releaseDate | date("F j, Y") }}
    </li>
{% endfor %}
</ul>
{% endif %}

<p>Please log in to your control panel to install these updates.</p>'
        ];
    }

    /**
     * @inheritDoc
     */
    public function getConditions(): array
    {
        return [
            'condition1' => [
                'operand' => 'updates.hasCriticalUpdates == condition',
                'name' => Craft::t('mailcraft', 'Update Level'),
                'options' => [
                    [
                        'text' => Craft::t('mailcraft', 'Any updates'),
                        'value' => '0',
                    ],
                    [
                        'text' => Craft::t('mailcraft', 'Only critical updates'),
                        'value' => '1',
                    ],
                ],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function testConditions($template, $variables): bool
    {
        $conditionValue = (int)($template->condition1 ?? '0');
        $eventLevel = $variables['level'] ?? self::EVENT_NEW_UPDATES;


        // template level = 1 :: any updates
        if ($conditionValue === 1 && $eventLevel !== self::EVENT_NEW_CRITICAL_UPDATES) {
            return false;
        }

        // template level = 0 :: critical updates
        if ($conditionValue === 0 && $eventLevel === self::EVENT_NEW_CRITICAL_UPDATES) {
            return true;
        }

        // template level = 0 :: critical updates
        if ($conditionValue === 0 && $eventLevel !== self::EVENT_NEW_UPDATES) {
            return false;
        }

        return true;
    }
}