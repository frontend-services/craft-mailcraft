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
    /**
     * @inheritDoc
     */
    public function getEventId(): string
    {
        return 'entry.update';
    }

    /**
     * @inheritDoc
     */
    public function getEventDetails(): array
    {
        return [
            'label' => 'Entry is Updated',
            'group' => 'Entries',
        ];
    }

    /**
     * @inheritDoc
     */
    public function registerEventListener(callable $handler): void
    {
        $captured = [];
        $attributeLabels = [
            'title' => 'Title',
            'slug' => 'Slug',
            'postDate' => 'Post date',
            'expiryDate' => 'Expiry date',
            'enabled' => 'Status',
            'enabledForSite' => 'Status',
            'authorId' => 'Author',
            'authorIds' => 'Authors',
            'typeId' => 'Entry type',
            'parentId' => 'Parent',
            'uri' => 'URI',
        ];

        // Dirty attributes/fields are only known before the save, so capture them here and hand them over once EVENT_AFTER_SAVE confirms the save went through.
        Event::on(
            Entry::class,
            Element::EVENT_BEFORE_SAVE,
            static function(ModelEvent $event) use (&$captured, $attributeLabels) {
                /** @var Entry $entry */
                $entry = $event->sender;
                if (
                    !$entry->id ||
                    $entry->firstSave ||
                    $entry->propagating ||
                    $entry->resaving ||
                    $entry->getIsDraft() ||
                    $entry->getIsRevision()
                ) {
                    return;
                }

                $changedLabels = [];
                foreach ($entry->getDirtyAttributes() as $attr) {
                    if (in_array($attr, ['uid', 'dateUpdated', 'dateLastMerged', 'revisionNotes'], true)) {
                        continue;
                    }
                    $label = $attributeLabels[$attr] ?? ucfirst($attr);
                    $changedLabels[$label] = true;
                }
                foreach ($entry->getDirtyFields() as $fieldHandle) {
                    $field = Craft::$app->getFields()->getFieldByHandle($fieldHandle);
                    $label = $field ? $field->name : ucfirst($fieldHandle);
                    $changedLabels[$label] = true;
                }

                $captured[$entry->id . ':' . $entry->siteId] = array_keys($changedLabels);
            }
        );

        Event::on(
            Entry::class,
            Element::EVENT_AFTER_SAVE,
            static function(ModelEvent $event) use ($handler, &$captured) {
                /** @var Entry $entry */
                $entry = $event->sender;
                if ($entry->sectionId === null) {
                    return;
                }
                $key = $entry->id . ':' . $entry->siteId;
                $changedLabels = $captured[$key] ?? [];
                unset($captured[$key]);

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
                    $handler([
                        'entry' => $entry,
                        'changedFields' => $changedLabels,
                        'changedFieldsList' => implode(', ', $changedLabels),
                    ]);
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
            'description' => 'The entry that was updated',
            'fields' => TemplateHelper::entryFields(),
        ];
        $variables += TemplateHelper::changedFieldsVariables();
        return $variables;
    }

    /**
     * @inheritDoc
     */
    public function getTemplateExample(): array
    {
        return [
            'id' => $this->getEventId(),
            'title' => 'Entry Update Notification',
            'subject' => 'Content Updated: {{entry.title}}',
            'template' => '<h1>Entry Updated</h1>
<p>The entry "{{entry.title}}" has been updated.</p>
{% if changedFieldsList %}<p><strong>Changed:</strong> {{changedFieldsList}}</p>{% endif %}
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

        if (isset($template->condition1) && $template->condition1 && $entry->getSection()?->handle !== $template->condition1) {
            return false;
        }

        if (isset($template->condition2) && $template->condition2 && $entry->getType()->handle !== $template->condition2) {
            return false;
        }

        return $this->matchesExtraConditions($template, $variables);
    }
}