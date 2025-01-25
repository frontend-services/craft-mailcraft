<?php

namespace frontendservices\mailcraft\events;

class TriggerEvents
{
    // Entry events
    public const EVENT_ENTRY_CREATE = 'entry.create';
    public const EVENT_ENTRY_UPDATE = 'entry.update';
    public const EVENT_ENTRY_DELETE = 'entry.delete';

    // User events
    public const EVENT_USER_CREATE = 'user.create';
    public const EVENT_USER_UPDATE = 'user.update';
    public const EVENT_USER_VERIFY = 'user.verify';

    // Commerce events
    public const EVENT_COMMERCE_ORDER_COMPLETE = 'commerce.order.complete';
    public const EVENT_COMMERCE_ORDER_STATUS = 'commerce.order.status';

    public const EVENT_DETAILS = [
        self::EVENT_ENTRY_CREATE => [
            'label' => 'When Entry is Created',
            'group' => 'entries',
        ],
        self::EVENT_ENTRY_UPDATE => [
            'label' => 'When Entry is Updated',
            'group' => 'entries',
        ],
        self::EVENT_ENTRY_DELETE => [
            'label' => 'When Entry is Deleted',
            'group' => 'entries',
        ],
        self::EVENT_USER_CREATE => [
            'label' => 'When User is Created',
            'group' => 'users',
        ],
        self::EVENT_USER_UPDATE => [
            'label' => 'When User is Updated',
            'group' => 'users',
        ],
        self::EVENT_USER_VERIFY => [
            'label' => 'When User Verifies Email',
            'group' => 'users',
        ],
        self::EVENT_COMMERCE_ORDER_COMPLETE => [
            'label' => 'When Order is Complete',
            'group' => 'commerce',
        ],
        self::EVENT_COMMERCE_ORDER_STATUS => [
            'label' => 'When Order Status Changes',
            'group' => 'commerce',
        ],
    ];

    /**
     * Get all available events for the current edition
     */
    public static function getAvailableEvents(): array
    {
        $events = [];

        foreach (self::EVENT_DETAILS as $key => $details) {
            $group = $details['group'];

            if (!isset($events[$group])) {
                $events[$group] = [];
            }

            $events[$group][$key] = $details;
        }

        return $events;
    }

    /**
     * Get available events for filtering
     */
    public static function getAvailableEventsList(): array
    {
        $events = self::getAvailableEvents();
        $list = [];

        foreach ($events as $group => $event) {
            foreach ($event as $key => $label) {
                $list[$key] = $label['label'];
            }
        }

        return array_keys($list);
    }

    /**
     * Get event name without group prefix
     */
    public static function getEventName(string $event): string
    {
        $parts = explode('.', $event);
        return end($parts);
    }

    /**
     * Get event group
     */
    public static function getEventGroup(string $event): string
    {
        $parts = explode('.', $event);
        return $parts[0];
    }
}