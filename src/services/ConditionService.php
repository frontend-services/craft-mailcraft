<?php

namespace frontendservices\mailcraft\services;

use Craft;
use craft\base\Component;
use craft\records\EntryType;
use craft\records\Section;
use frontendservices\mailcraft\MailCraft;

/**
 *
 * @property-read array[] $userGroups
 * @property-read array[] $entryTypes
 * @property-read array[] $entrySections
 */
class ConditionService extends Component
{
//    private array $conditions = [];
    private array $sections = [];

    public function init(): void
    {
        parent::init();

        $this->sections = Craft::$app->entries->allSections;
    }

    public function getAllConditions(): array
    {
        $conditions = [];
        $providers = MailCraft::getInstance()->eventRegistry->getProviders();

        foreach ($providers as $provider) {
            $conditions[$provider->getEventId()] = $provider->getConditions();
        }

        return $conditions;
    }

    public function getEntrySections(): array
    {
        $options = [
            [
                'value' => false,
                'text' => Craft::t('mailcraft', 'Any')
            ]
        ];
        foreach ($this->sections as $section) {
            $options[] = [
                'value' => $section->handle,
                'text' => $section->name
            ];
        }
        return $options;
    }

    public function getEntryTypes(): array
    {
        $options = [];
        /** @var Section $section */
        foreach ($this->sections as $section) {

            $sectionOptions = [
                [
                    'value' => false,
                    'text' => Craft::t('mailcraft', 'Any')
                ]
            ];

            $entryTypes = Craft::$app->entries->getEntryTypesBySectionId($section->id);
            /** @var EntryType $entryType */
            foreach ($entryTypes as $entryType) {
                $sectionOptions[] = [
                    'value' => $entryType->handle,
                    'text' => $entryType->name
                ];
            }
            $options[$section->handle] = $sectionOptions;
        }
        return $options;
    }

    public function getUserGroups(): array
    {
        $groups = Craft::$app->getUserGroups()->getAllGroups();
        $options = [
            [
                'value' => false,
                'text' => Craft::t('mailcraft', 'Any')
            ]
        ];
        foreach ($groups as $group) {
            $options[] = [
                'value' => $group->handle,
                'text' => $group->name
            ];
        }
        return $options;
    }
}