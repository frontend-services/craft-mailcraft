<?php
namespace frontendservices\mailcraft\events\providers;

use Craft;
use craft\base\Element;
use frontendservices\mailcraft\base\AbstractEventProvider;
use craft\elements\Asset;
use frontendservices\mailcraft\helpers\TemplateHelper;
use yii\base\Event;
use yii\base\ModelEvent;

class AssetUpdateEventProvider extends AbstractEventProvider
{
    /**
     * @inheritDoc
     */
    public function getEventId(): string
    {
        return 'asset.update';
    }

    /**
     * @inheritDoc
     */
    public function getEventDetails(): array
    {
        return [
            'label' => 'Asset is Updated',
            'group' => 'Assets',
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
            'folderId' => 'Folder',
            'width' => 'Width',
            'height' => 'Height',
            'size' => 'File size',
            'alt' => 'Alt text',
            'enabled' => 'Status',
        ];

        // Capture dirty state before save, because Craft clears it in afterSave()
        // before EVENT_AFTER_SAVE fires.
        Event::on(
            Asset::class,
            Element::EVENT_BEFORE_SAVE,
            static function(ModelEvent $event) use (&$captured, $attributeLabels) {
                /** @var Asset $asset */
                $asset = $event->sender;
                if ($asset->firstSave || $asset->propagating || $asset->resaving) {
                    return;
                }

                $changedLabels = [];

                foreach ($asset->getDirtyAttributes() as $attr) {
                    // Skip internal transient properties
                    if (in_array($attr, ['uid', 'dateUpdated'], true)) {
                        continue;
                    }
                    $label = $attributeLabels[$attr] ?? ucfirst($attr);
                    $changedLabels[$label] = true;
                }

                foreach ($asset->getDirtyFields() as $fieldHandle) {
                    $field = Craft::$app->getFields()->getFieldByHandle($fieldHandle);
                    $label = $field ? $field->name : ucfirst($fieldHandle);
                    $changedLabels[$label] = true;
                }

                // Detect file replacement vs image editor (crop/flip/rotate).
                // Both go through replaceAssetFile() and set SCENARIO_REPLACE,
                // so we distinguish by the current request action.
                if ($asset->getScenario() === Asset::SCENARIO_REPLACE) {
                    $request = Craft::$app->getRequest();
                    $isImageEdit = !$request->getIsConsoleRequest() &&
                        $request->getActionSegments() === ['assets', 'save-image'];

                    if ($isImageEdit) {
                        $changedLabels['Image edited'] = true;
                    } else {
                        $changedLabels['File replaced'] = true;
                    }
                }

                // Focal point uses a setter (setFocalPoint) that bypasses dirty
                // attribute tracking, so compare against the stored version directly.
                // Filename is also compared directly because Craft marks it dirty
                // on crop/replace even when the name hasn't actually changed.
                $original = Craft::$app->getAssets()->getAssetById($asset->id);
                if ($original) {
                    if ($original->filename !== $asset->filename) {
                        $changedLabels['Filename'] = true;
                    }
                    if ($original->hasFocalPoint !== $asset->hasFocalPoint ||
                        $original->getFocalPoint() !== $asset->getFocalPoint()
                    ) {
                        $changedLabels['Focal point'] = true;
                    }
                }

                if ($asset->id) {
                    $captured[$asset->id] = array_keys($changedLabels);
                }
            }
        );

        Event::on(
            Asset::class,
            Element::EVENT_AFTER_SAVE,
            static function(ModelEvent $event) use ($handler, &$captured) {
                /** @var Asset $asset */
                $asset = $event->sender;
                if (
                    !$asset->firstSave &&
                    !$asset->propagating &&
                    !$asset->resaving
                ) {
                    $changedLabels = $captured[$asset->id] ?? [];
                    unset($captured[$asset->id]);

                    $handler([
                        'asset' => $asset,
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
        $variables['asset'] = [
            'type' => 'Asset',
            'description' => 'Asset model',
            'fields' => [
                'title' => 'Asset title',
                'filename' => 'Asset filename',
                'volume' => 'Volume name',
                'kind' => 'File kind (image, document, etc.)',
                'size' => 'File size in bytes',
            ]
        ];
        $variables['changedFields'] = [
            'type' => 'array',
            'description' => 'List of changed field labels',
        ];
        $variables['changedFieldsList'] = [
            'type' => 'string',
            'description' => 'Comma-separated list of changed field labels',
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
            'title' => 'Asset Update Notification',
            'subject' => 'Asset Updated: {{asset.title}}',
            'template' => '<h1>Asset Updated</h1>
<p>The asset "{{asset.title}}" ({{asset.filename}}) has been updated.</p>
{% if changedFieldsList %}<p><strong>Changed:</strong> {{changedFieldsList}}</p>{% endif %}
<p>Edit it <a href="{{asset.cpEditUrl}}">here</a>.</p>',
        ];
    }

    /**
     * @inheritDoc
     */
    public function getConditions(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function testConditions($template, $variables): bool
    {
        if (isset($template->conditions) && $template->conditions) {
            try {
                $twig = Craft::$app->getView()->renderString("{{" . $template->conditions . "}}", $variables);
                return Craft::$app->getView()->renderString($twig) === "1";
            } catch (\Throwable $e) {
                return false;
            }
        }

        return true;
    }
}
