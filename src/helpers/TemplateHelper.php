<?php

namespace frontendservices\mailcraft\helpers;

class TemplateHelper
{
    /**
     * Get general variables
     */
    public static function getGeneralVariables(): array
    {
        return [
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
    }
    
    /**
     * Get available variables for an event
     */
    public static function getEventVariables(string $event): array
    {
        return [
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
    }
}