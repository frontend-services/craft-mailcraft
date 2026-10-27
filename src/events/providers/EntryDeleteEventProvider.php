<?php
namespace frontendservices\mailcraft\events\providers;

use Craft;
use craft\base\Element;
use frontendservices\mailcraft\base\AbstractEventProvider;
use craft\elements\Entry;
use frontendservices\mailcraft\helpers\TemplateHelper;
use frontendservices\mailcraft\MailCraft;
use yii\base\Event;

class EntryDeleteEventProvider extends AbstractEventProvider
{
    /**
     * @inheritDoc
     */
    public function getEventId(): string
    {
        return 'entry.delete';
    }

    /**
     * @inheritDoc
     */
    public function getEventDetails(): array
    {
        return [
            'label' => 'Entry is Deleted',
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
            Element::EVENT_AFTER_DELETE,
            static function(Event $event) use ($handler) {
                /** @var Entry $entry */
                $entry = $event->sender;

                if ($entry->sectionId === null) {
                    return;
                }

                $handler(['entry' => $entry]);
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
            'description' => 'The entry that was deleted',
            'fields' => TemplateHelper::entryFields('entry', true),
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
            'title' => 'Entry Deleted Notification',
            'subject' => 'Entry Deleted: {{entry.title}}',
            'template' => '<h1>Entry Deleted</h1>
<p>The entry "{{entry.title}}" has been deleted.</p>',
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

        if (isset($template->condition1) && $template->condition1 && $entry->getSection()?->handle !== $template->condition1) {
            return false;
        }

        if (isset($template->condition2) && $template->condition2 && $entry->getType()->handle !== $template->condition2) {
            return false;
        }

        return $this->matchesExtraConditions($template, $variables);
    }
}
