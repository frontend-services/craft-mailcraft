<?php
namespace frontendservices\mailcraft\events\providers;

use Craft;
use craft\base\Element;
use frontendservices\mailcraft\base\AbstractEventProvider;
use craft\elements\Entry;
use frontendservices\mailcraft\helpers\TemplateHelper;
use frontendservices\mailcraft\MailCraft;
use yii\base\Event;
use yii\base\ModelEvent;

class EntryUpdateEventProvider extends AbstractEventProvider
{
    public function getEventId(): string
    {
        return 'entry.update';
    }

    public function getEventDetails(): array
    {
        return [
            'label' => 'When Entry is Updated',
            'group' => 'entries',
        ];
    }

    public function registerEventListener(callable $handler): void
    {
        Event::on(
            Entry::class,
            Element::EVENT_AFTER_SAVE,
            static function(ModelEvent $event) use ($handler) {
                /** @var Entry $entry */
                $entry = $event->sender;
                if (
                    !($entry->getIsDraft()) &&
                    !($entry->duplicateOf && $entry->getIsCanonical() && !$entry->updatingFromDerivative) &&
                    ($entry->enabled && $entry->getEnabledForSite()) &&
                    !$entry->firstSave &&
                    !$entry->propagating &&
                    !$entry->isProvisionalDraft &&
                    !$entry->resaving &&
                    !($entry->getIsRevision())
                ) {
                    $handler(['entry' => $entry]);
                }
            }
        );
    }

    public function getSampleData(): array
    {
        return [
            'entry' => Entry::find()->one() ?? new Entry([
                'title' => 'Sample Entry',
                'url' => '/sample-entry',
                'sectionId' => 1
            ])
        ];
    }

    public function getTemplateVariables(): array
    {
        $variables = TemplateHelper::getGeneralVariables();
        $variables['entry'] = [
            'type' => 'Entry',
            'description' => 'Entry model',
            'fields' => [
                'title' => 'Entry title',
                'url' => 'Entry URL',
                'section' => 'Section name',
                'type' => 'Entry type',
                'customFields.*' => 'Any custom fields',
            ]
        ];

        return $variables;
    }

    public function getTemplateExample(): array
    {
        return [
            'title' => 'Entry Update Notification',
            'subject' => 'Content Updated: {{entry.title}}',
            'template' => '<p>The entry <a href="{{entry.url}}">{{entry.title}}</a> has been updated.</p>'
        ];
    }

    public function getConditions(): array
    {
        return [
            'condition1' => [
                'operand' => 'entry.section.handle == condition',
                'name' => Craft::t('mailcraft', 'Section'),
                'options' => MailCraft::getInstance()->conditionService->getEntrySections(),
                'dependant' => true,
            ],
            'condition2' => [
                'operand' => 'entry.type.handle == condition',
                'name' => Craft::t('mailcraft', 'Entry Type'),
                'options' => MailCraft::getInstance()->conditionService->getEntryTypes(),
            ],
        ];
    }

    /**
     * Test conditions to see if element is legible for sending
     *
     * @param $template
     * @param $variables
     * @return bool
     */
    public function testConditions($template, $variables): bool
    {
        // first test condition1 and condition2 if they exist and are set
        $entry = $variables['entry'] ?? null;
        if (!$entry) {
            return false;
        }

        if (isset($template->condition1) && $template->condition1 && $entry->section->handle !== $template->condition1) {
            return false;
        }

        if (isset($template->condition2) && $template->condition2 && $entry->type->handle !== $template->condition2) {
            return false;
        }

        // now test the extra conditions if they exist (they are set as twig conditions, e.g. `entry.section=="news"`)
        if (isset($template->conditions) && $template->conditions) {
            $twig = Craft::$app->getView()->renderString($template->conditions, [
                'entry' => $entry,
            ]);
            try {
                return Craft::$app->getView()->renderString($twig);
            } catch (\Throwable $e) {
                return false;
            }
        }

        return true;
    }
}