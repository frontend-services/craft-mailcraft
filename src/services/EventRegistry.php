<?php
namespace frontendservices\mailcraft\services;

use craft\base\Component;
use frontendservices\mailcraft\base\AbstractEventProvider;

/**
 * EventRegistry service
 *
 * @property-read array $availableEventsList
 * @property-read array $allEvents
 */
class EventRegistry extends Component
{
    private array $providers = [];

    public function registerProvider(AbstractEventProvider $provider): void
    {
        $this->providers[$provider->getEventId()] = $provider;
    }

    public function getProvider(string $eventId): ?AbstractEventProvider
    {
        return $this->providers[$eventId] ?? null;
    }

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

    public function getSampleData(string $eventId): array
    {
        return $this->getProvider($eventId)?->getSampleData() ?? [];
    }

    public function getTemplateVariables(string $eventId): array
    {
        return $this->getProvider($eventId)?->getTemplateVariables() ?? [];
    }

    public function getTemplateExample(string $eventId): array
    {
        return $this->getProvider($eventId)?->getTemplateExample() ?? [];
    }

    public function getProviders(): array
    {
        return $this->providers;
    }

    public function getAvailableEventsList(): array
    {
        return array_keys($this->providers);
    }
}