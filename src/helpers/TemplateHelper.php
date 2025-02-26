<?php

namespace frontendservices\mailcraft\helpers;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use frontendservices\mailcraft\events\TriggerEvents;

class TemplateHelper
{
    /**
     * Get available variables for an event
     */
    public static function getEventVariables(string $event): array
    {
        // Global variables available in all templates
        $variables = [
            'now' => [
                'type' => 'DateTime',
                'description' => 'Current date/time',
                'example' => '{{ now|date("Y-m-d") }}'
            ],
            'siteUrl' => [
                'type' => 'string',
                'description' => 'Site URL',
                'example' => '{{ siteUrl }}'
            ],
            'siteName' => [
                'type' => 'string',
                'description' => 'Site name',
                'example' => '{{ siteName }}'
            ]
        ];

        // Event-specific variables
        switch ($event) {
            case TriggerEvents::EVENT_USER_CREATE:
            case TriggerEvents::EVENT_USER_UPDATE:
            case TriggerEvents::EVENT_USER_VERIFY:
            case TriggerEvents::EVENT_USER_LOGIN:
            case TriggerEvents::EVENT_USER_DELETE:
                $variables['user'] = [
                    'type' => 'User',
                    'description' => 'User model',
                    'fields' => [
                        'email' => 'User email address',
                        'username' => 'Username',
                        'firstName' => 'First name',
                        'lastName' => 'Last name',
                        'fullName' => 'Full name',
                    ],
                    'example' => '{{ user.email }}, {{ user.fullName }}'
                ];
                break;

            case TriggerEvents::EVENT_ENTRY_CREATE:
            case TriggerEvents::EVENT_ENTRY_UPDATE:
            case TriggerEvents::EVENT_ENTRY_DELETE:
                $variables['entry'] = [
                    'type' => 'Entry',
                    'description' => 'Entry model',
                    'fields' => [
                        'title' => 'Entry title',
                        'url' => 'Entry URL',
                        'section' => 'Section name',
                        'type' => 'Entry type',
                        'customFields.*' => 'Any custom fields',
                    ],
                    'example' => '{{ entry.title }}, {{ entry.url }}'
                ];
                break;

            case TriggerEvents::EVENT_COMMERCE_ORDER_COMPLETE:
            case TriggerEvents::EVENT_COMMERCE_ORDER_DELETE:
                $variables['order'] = [
                    'type' => 'Order',
                    'description' => 'Commerce order model',
                    'fields' => [
                        'number' => 'Order number',
                        'totalPrice' => 'Total price',
                        'status' => 'Order status',
                        'customer' => 'Customer info',
                        'lineItems' => 'Order items',
                    ],
                    'example' => "{{ order.number }}\n{% for item in order.lineItems %}{{ item.description }}{% endfor %}"
                ];
                break;

            case TriggerEvents::EVENT_COMMERCE_ORDER_STATUS:
                $variables['order'] = [
                    'type' => 'Order',
                    'description' => 'Commerce order model',
                    'fields' => [
                        'number' => 'Order number',
                        'totalPrice' => 'Total price',
                        'status' => 'Order status',
                        'customer' => 'Customer info',
                        'lineItems' => 'Order items',
                    ],
                    'example' => '{{ order.number }}'
                ];
                $variables['oldStatus'] = [
                    'type' => 'OrderStatus',
                    'description' => 'Previous order status',
                    'example' => '{{ oldStatus.name }}'
                ];
                $variables['newStatus'] = [
                    'type' => 'OrderStatus',
                    'description' => 'New order status',
                    'example' => '{{ newStatus.name }}'
                ];
                break;
        }

        return $variables;
    }

    /**
     * Get sample data for preview
     */
    public static function getSampleData(string $event): array
    {
        $data = [
            'now' => new \DateTime(),
            'siteUrl' => Craft::$app->sites->primarySite->baseUrl,
            'siteName' => Craft::$app->sites->primarySite->name,
        ];

        switch ($event) {
            case TriggerEvents::EVENT_USER_CREATE:
            case TriggerEvents::EVENT_USER_UPDATE:
            case TriggerEvents::EVENT_USER_VERIFY:
            case TriggerEvents::EVENT_USER_LOGIN:
            case TriggerEvents::EVENT_USER_DELETE:
                $data['user'] = User::find()->one() ?? new User([
                    'email' => 'sample@example.com',
                    'username' => 'sampleuser',
                    'firstName' => 'John',
                    'lastName' => 'Doe'
                ]);
                break;

            case TriggerEvents::EVENT_ENTRY_CREATE:
            case TriggerEvents::EVENT_ENTRY_UPDATE:
            case TriggerEvents::EVENT_ENTRY_DELETE:
                $data['entry'] = Entry::find()->one() ?? new Entry([
                    'title' => 'Sample Entry',
                    'url' => '/sample-entry',
                    'sectionId' => 1
                ]);
                break;

            // Add Commerce examples if plugin is installed
            case TriggerEvents::EVENT_COMMERCE_ORDER_COMPLETE:
            case TriggerEvents::EVENT_COMMERCE_ORDER_STATUS:
            case TriggerEvents::EVENT_COMMERCE_ORDER_DELETE:
                if (class_exists('craft\commerce\elements\Order')) {
                    $data['order'] = new \craft\commerce\elements\Order([
                        'number' => 'SAMPLE-001',
                        'totalPrice' => 99.99,
                        'email' => 'customer@example.com'
                    ]);

                    if ($event === TriggerEvents::EVENT_COMMERCE_ORDER_STATUS) {
                        $data['oldStatus'] = new \craft\commerce\models\OrderStatus([
                            'name' => 'Processing'
                        ]);
                        $data['newStatus'] = new \craft\commerce\models\OrderStatus([
                            'name' => 'Completed'
                        ]);
                    }
                }
                break;
        }

        return $data;
    }
}