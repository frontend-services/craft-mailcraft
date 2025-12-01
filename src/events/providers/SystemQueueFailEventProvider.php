<?php
namespace frontendservices\mailcraft\events\providers;

use Craft;
use frontendservices\mailcraft\base\AbstractEventProvider;
use frontendservices\mailcraft\helpers\TemplateHelper;
use yii\base\Event;
use yii\queue\ExecEvent;
use yii\queue\Queue;

class SystemQueueFailEventProvider extends AbstractEventProvider
{
    /**
     * @inheritDoc
     */
    public function getEventId(): string
    {
        return 'system.queue.fail';
    }

    /**
     * @inheritDoc
     */
    public function getEventDetails(): array
    {
        return [
            'label' => 'Queue Job Failed',
            'group' => 'System',
        ];
    }

    /**
     * @inheritDoc
     */
    public function registerEventListener(callable $handler): void
    {
        Event::on(
            Queue::class,
            Queue::EVENT_AFTER_ERROR,
            static function(ExecEvent $event) use ($handler) {
                $handler([
                    'job' => $event->job,
                    'error' => $event->error,
                    'attempt' => $event->attempt,
                ]);
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function getTemplateVariables(): array
    {
        $variables = TemplateHelper::getGeneralVariables();
        $variables['job'] = [
            'type' => 'object',
            'description' => 'The failed job object',
            'fields' => [
                'description' => 'Job description',
            ]
        ];
        $variables['error'] = [
            'type' => 'Exception',
            'description' => 'The error that occurred',
            'fields' => [
                'message' => 'Error message',
                'file' => 'File where error occurred',
                'line' => 'Line number where error occurred',
            ]
        ];
        $variables['attempt'] = [
            'type' => 'integer',
            'description' => 'The attempt number'
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
            'title' => 'Queue Job Failed Notification',
            'subject' => 'Queue Job Failed on {{ siteName }}',
            'template' => '<h1>Queue Job Failed</h1>

<p>A queue job has failed on {{ siteName }}.</p>

<h2>Job Details</h2>
<p><strong>Description:</strong> {{ job.description }}</p>
<p><strong>Attempt:</strong> {{ attempt }}</p>

<h2>Error Details</h2>
<p><strong>Message:</strong> {{ error.message }}</p>
<p><strong>File:</strong> {{ error.file }}</p>
<p><strong>Line:</strong> {{ error.line }}</p>'
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
