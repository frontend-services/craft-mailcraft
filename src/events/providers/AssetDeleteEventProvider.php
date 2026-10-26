<?php
namespace frontendservices\mailcraft\events\providers;

use Craft;
use craft\base\Element;
use frontendservices\mailcraft\base\AbstractEventProvider;
use craft\elements\Asset;
use frontendservices\mailcraft\helpers\TemplateHelper;
use yii\base\Event;

class AssetDeleteEventProvider extends AbstractEventProvider
{
    /**
     * @inheritDoc
     */
    public function getEventId(): string
    {
        return 'asset.delete';
    }

    /**
     * @inheritDoc
     */
    public function getEventDetails(): array
    {
        return [
            'label' => 'Asset is Deleted',
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
            Element::EVENT_AFTER_DELETE,
            static function(Event $event) use ($handler) {
                /** @var Asset $asset */
                $asset = $event->sender;
                $handler(['asset' => $asset]);
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

        return $variables;
    }

    /**
     * @inheritDoc
     */
    public function getTemplateExample(): array
    {
        return [
            'id' => $this->getEventId(),
            'title' => 'Asset Deleted Notification',
            'subject' => 'Asset Deleted: {{asset.title}}',
            'template' => '<h1>Asset Deleted</h1>
<p>The asset "{{asset.title}}" ({{asset.filename}}) has been deleted.</p>',
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
