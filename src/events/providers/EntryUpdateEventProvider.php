<?php
namespace frontendservices\mailcraft\events\providers;

use craft\base\Element;
use frontendservices\mailcraft\base\AbstractEventProvider;
use craft\elements\Entry;
use frontendservices\mailcraft\helpers\TemplateHelper;
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
}