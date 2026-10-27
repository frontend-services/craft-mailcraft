<?php

namespace frontendservices\mailcraft\helpers;

use craft\helpers\UrlHelper;

/**
 * Template variable declarations shared by the event providers.
 *
 * A variable definition looks like:
 *
 * ```php
 * 'user' => [
 *     'type' => 'User',                // drives the preview's sample data
 *     'description' => 'The user',
 *     'fields' => [
 *         'email' => 'Email address',  // plain label -> {{ user.email }}
 *         'dateCreated' => [           // label + ready-made snippet
 *             'label' => 'Registered on',
 *             'example' => "{{ user.dateCreated|date('d.m.Y') }}",
 *         ],
 *         'author.email' => '…',       // dotted keys reach nested objects
 *     ],
 * ]
 * ```
 *
 * Variables without `fields` are listed by their `example` (or `{{ key }}`).
 */
class TemplateHelper
{
    public const DATE_FORMAT = "d.m.Y";

    /**
     * Variables available to every event.
     *
     * `now`, `siteName`, `siteUrl` and `currentSite` are Craft Twig globals;
     * `cpUrl` is injected by MailCraft (see getRuntimeVariables()).
     */
    public static function getGeneralVariables(): array
    {
        return [
            'now' => [
                'type' => 'DateTime',
                'description' => 'Current date/time',
                'example' => "{{ now|date('" . self::DATE_FORMAT . "') }}",
            ],
            'siteName' => [
                'type' => 'string',
                'description' => 'Site name',
                'example' => '{{ siteName }}',
            ],
            'siteUrl' => [
                'type' => 'string',
                'description' => 'Site URL',
                'example' => '{{ siteUrl }}',
            ],
            'cpUrl' => [
                'type' => 'string',
                'description' => 'Control panel URL',
                'example' => '{{ cpUrl }}',
            ],
            'currentSite' => [
                'type' => 'Site',
                'description' => 'The site the event happened on',
                'fields' => [
                    'name' => 'Site name',
                    'language' => 'Site language',
                ],
            ],
        ];
    }

    /**
     * Variables that MailCraft adds to every event payload at send time.
     */
    public static function getRuntimeVariables(): array
    {
        return [
            'cpUrl' => UrlHelper::cpUrl(),
        ];
    }

    /**
     * @deprecated Use getGeneralVariables()
     */
    public static function getEventVariables(string $event): array
    {
        return self::getGeneralVariables();
    }

    /**
     * Field list for a `user` variable.
     *
     * @param string $var Variable name used in the examples
     */
    public static function userFields(string $var = 'user', bool $deleted = false): array
    {
        $fields = [
            'email' => 'Email address',
            'fullName' => 'Full name',
            'firstName' => 'First name',
            'lastName' => 'Last name',
            'friendlyName' => 'First name, or username when empty',
            'username' => 'Username',
            'cpEditUrl' => 'Link to the user in the control panel',
            'dateCreated' => [
                'label' => 'Registered on',
                'example' => self::dateExample($var . '.dateCreated'),
            ],
            'lastLoginDate' => [
                'label' => 'Last login',
                'example' => self::dateExample($var . '.lastLoginDate'),
            ],
            'status' => 'Status (active, pending, suspended, inactive)',
            'admin' => 'Is admin (true/false)',
            'groups' => [
                'label' => 'User groups, comma separated',
                'example' => "{{ $var.groups|map(g => g.name)|join(', ') }}",
            ],
        ];

        if ($deleted) {
            unset($fields['cpEditUrl']);
        }

        return $fields;
    }

    /**
     * Field list for an `entry` variable.
     *
     * @param bool $deleted Leave out fields that no longer resolve once the entry is gone
     */
    public static function entryFields(string $var = 'entry', bool $deleted = false): array
    {
        $fields = [
            'title' => 'Entry title',
            'url' => 'Public URL',
            'cpEditUrl' => 'Link to the entry in the control panel',
            'section.name' => 'Section name',
            'type.name' => 'Entry type name',
            'slug' => 'Slug',
            'status' => 'Status (live, pending, expired, disabled)',
            'postDate' => [
                'label' => 'Post date',
                'example' => self::dateExample($var . '.postDate'),
            ],
            'dateUpdated' => [
                'label' => 'Last updated',
                'example' => self::dateExample($var . '.dateUpdated'),
            ],
            'author.fullName' => 'Author name',
            'author.email' => 'Author email',
            'site.name' => 'Site name',
        ];

        if ($deleted) {
            unset($fields['url'], $fields['cpEditUrl']);
        }

        return $fields;
    }

    /**
     * Field list for an `asset` variable.
     */
    public static function assetFields(string $var = 'asset', bool $deleted = false): array
    {
        $fields = [
            'title' => 'Asset title',
            'filename' => 'Filename',
            'url' => 'Public URL',
            'cpEditUrl' => 'Link to the asset in the control panel',
            'kind' => 'File kind (image, pdf, video…)',
            'extension' => 'File extension',
            'size' => [
                'label' => 'File size, formatted',
                'example' => "{{ $var.size|filesize }}",
            ],
            'width' => 'Image width in px',
            'height' => 'Image height in px',
            'volume.name' => 'Volume name',
            'folder.name' => 'Folder name',
            'uploader.fullName' => 'Uploaded by (name)',
            'uploader.email' => 'Uploaded by (email)',
            'dateCreated' => [
                'label' => 'Uploaded on',
                'example' => self::dateExample($var . '.dateCreated'),
            ],
        ];

        if ($deleted) {
            unset($fields['url'], $fields['cpEditUrl']);
        }

        return $fields;
    }

    /**
     * Field list for a Commerce `order` variable.
     */
    public static function orderFields(string $var = 'order'): array
    {
        return [
            'number' => 'Order number',
            'reference' => 'Order reference',
            'email' => 'Customer email',
            'customer.fullName' => 'Customer name',
            'orderStatus.name' => 'Current order status',
            'totalPrice' => [
                'label' => 'Order total, formatted',
                'example' => self::moneyExample($var, 'totalPrice'),
            ],
            'itemTotal' => [
                'label' => 'Items total, formatted',
                'example' => self::moneyExample($var, 'itemTotal'),
            ],
            'totalShippingCost' => [
                'label' => 'Shipping cost, formatted',
                'example' => self::moneyExample($var, 'totalShippingCost'),
            ],
            'totalTax' => [
                'label' => 'Tax, formatted',
                'example' => self::moneyExample($var, 'totalTax'),
            ],
            'totalDiscount' => [
                'label' => 'Discount, formatted',
                'example' => self::moneyExample($var, 'totalDiscount'),
            ],
            'totalPaid' => [
                'label' => 'Amount paid, formatted',
                'example' => self::moneyExample($var, 'totalPaid'),
            ],
            'currency' => 'Order currency code',
            'shippingMethodName' => 'Shipping method',
            'couponCode' => 'Coupon code used',
            'dateOrdered' => [
                'label' => 'Date ordered',
                'example' => self::dateExample($var . '.dateOrdered'),
            ],
            'lineItems' => [
                'label' => 'Line items as a table',
                'example' => "{{ craft.mailCraft.orderDetails($var) }}",
            ],
            'shippingAddress' => [
                'label' => 'Shipping address, formatted',
                'example' => "{{ craft.app.addresses.formatAddress($var.shippingAddress)|raw }}",
            ],
            'billingAddress' => [
                'label' => 'Billing address, formatted',
                'example' => "{{ craft.app.addresses.formatAddress($var.billingAddress)|raw }}",
            ],
            'cpEditUrl' => 'Link to the order in the control panel',
        ];
    }

    /**
     * Variables shared by the "updated" events.
     */
    public static function changedFieldsVariables(): array
    {
        return [
            'changedFieldsList' => [
                'type' => 'string',
                'description' => 'Changed fields, comma separated',
                'example' => '{{ changedFieldsList }}',
            ],
            'changedFields' => [
                'type' => 'array',
                'description' => 'Changed fields as a list',
                'example' => "{% for field in changedFields %}{{ field }}{% if not loop.last %}, {% endif %}{% endfor %}",
            ],
        ];
    }

    public static function dateExample(string $path): string
    {
        return "{{ $path|date('" . self::DATE_FORMAT . "') }}";
    }

    public static function moneyExample(string $var, string $field): string
    {
        return "{{ $var.$field|commerceCurrency($var.currency) }}";
    }

    /**
     * Returns the plain label of a field definition (string or ['label' => …]).
     */
    public static function fieldLabel(mixed $definition, string $fallback = ''): string
    {
        if (is_string($definition)) {
            return $definition;
        }
        if (is_array($definition) && isset($definition['label']) && is_string($definition['label'])) {
            return $definition['label'];
        }
        return $fallback;
    }
}
