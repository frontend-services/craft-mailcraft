<?php
namespace frontendservices\mailcraft\services;

use craft\base\Component;
use frontendservices\mailcraft\base\AbstractEventProvider;

/**
 * EventRegistry service
 *
 * @property-read array $availableEventsList
 * @property-read array[] $sampleEmails
 * @property-read array $allEvents
 */
class EventRegistry extends Component
{
    private array $providers = [];

    /**
     * Registers an event provider.
     *
     * @param AbstractEventProvider $provider
     * @return void
     */
    public function registerProvider(AbstractEventProvider $provider): void
    {
        $this->providers[$provider->getEventId()] = $provider;
    }

    /**
     * Get all registered events.
     *
     * @return array
     */
    public function getAllEvents(): array
    {
        $events = [];
        foreach ($this->providers as $provider) {
            $details = $provider->getEventDetails();
            $group = $details['group'];
            if (!isset($events[$group])) {
                $events[$group] = [];
            }
            $events[$group][$provider->getEventId()] = $details;
        }
        return $events;
    }

    public function getProviders(): array
    {
        return $this->providers;
    }

    public function getAvailableEventsList(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Get all sample emails
     */
    public function getSampleEmails(): array
    {
        $sampleEmails = [
            [
                'value' => '',
                'label' => 'Select an example...',
            ],
        ];
        foreach ($this->providers as $provider) {
            /* @var $provider AbstractEventProvider */
            $templateExample = $provider->getTemplateExample();
            $sampleEmails[] = [
                'value' => $provider->getEventId(),
                'label' => $templateExample['title'],
            ];
        }
        return $sampleEmails;
    }
}