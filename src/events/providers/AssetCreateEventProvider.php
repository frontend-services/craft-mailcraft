<?php
namespace frontendservices\mailcraft\events\providers;

use Craft;
use craft\base\Element;
use frontendservices\mailcraft\base\AbstractEventProvider;
use craft\elements\Asset;
use frontendservices\mailcraft\helpers\TemplateHelper;
use yii\base\Event;
use yii\base\ModelEvent;

class AssetCreateEventProvider extends AbstractEventProvider
{
    /**
     * @inheritDoc
     */
    public function getEventId(): string
    {
        return 'asset.create';
    }

    /**
     * @inheritDoc
     */
    public function getEventDetails(): array
    {
        return [
            'label' => 'Asset is Created',
            'group' => 'Assets',
        ];
    }

    /**
     * @inheritDoc
     */
    public function registerEventListener(callable $handler): void
    {
        Event::on(
            Asset::class,
            Element::EVENT_AFTER_SAVE,
            static function(ModelEvent $event) use ($handler) {
                /** @var Asset $asset */
                $asset = $event->sender;
                if (
                    $asset->firstSave &&
                    !$asset->propagating
                ) {
                    $handler(['asset' => $asset]);
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
            'description' => 'The asset that was uploaded',
            'fields' => TemplateHelper::assetFields(),
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
            'title' => 'New Asset Notification',
            'subject' => 'New Asset Uploaded: {{asset.title}}',
            'template' => '<h1>New Asset Uploaded</h1>
<p>A new asset "{{asset.title}}" ({{asset.filename}}) has been uploaded.</p>
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
        return $this->matchesExtraConditions($template, $variables);
    }
}
