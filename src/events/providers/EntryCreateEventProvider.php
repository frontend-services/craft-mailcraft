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

class EntryCreateEventProvider extends AbstractEventProvider
{
    /**
     * @inheritDoc
     */
    public function getEventId(): string
    {
        return 'entry.create';
    }

    /**
     * @inheritDoc
     */
    public function getEventDetails(): array
    {
        return [
            'label' => 'When Entry is Created',
            'group' => 'Entries',
        ];
    }

    /**
     * @inheritDoc
     */
    public function registerEventListener(callable $handler): void
    {
        Event::on(
            Entry::class,
            Element::EVENT_AFTER_SAVE,
            static function(ModelEvent $event) use ($handler) {
                /** @var Entry $entry */
                $entry = $event->sender;
                if (
                    ($event->sender->enabled && $event->sender->getEnabledForSite()) &&
                    $event->sender->firstSave &&
                    !$event->sender->propagating
                ) {
                    $handler(['entry' => $entry]);
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

    /**
     * @inheritDoc
     */
    public function getTemplateExample(): array
    {
        return [
            'id' => $this->getEventId(),
            'title' => 'New Entry Notification',
            'subject' => 'New Content: {{entry.title}}',
            'template' => '<h1>New Entry Created</h1>
<p>A new entry "{{entry.title}}" has been created.</p>
<p>View it <a href="{{entry.url}}">here</a>.</p>
<p>Edit it <a href="{{entry.cpEditUrl}}">here</a>.</p>',
        ];
    }

    /**
     * @inheritDoc
     */
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
     * @inheritDoc
     */
    public function testConditions($template, $variables): bool
    {
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

        if (isset($template->conditions) && $template->conditions) {
            $twig = Craft::$app->getView()->renderString("{{".$template->conditions."}}", [
                'entry' => $entry,
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