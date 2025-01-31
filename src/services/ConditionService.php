<?php

namespace frontendservices\mailcraft\services;

use Craft;
use craft\base\Component;
use craft\records\EntryType;
use craft\records\Section;

/**
 *
 * @property-read array[] $userGroups
 * @property-read array[] $entryTypes
 * @property-read array[] $entrySections
 */
class ConditionService extends Component
{
    private array $conditions = [];
    private array $sections = [];

    public function init(): void
    {
        parent::init();

        $this->sections = Craft::$app->entries->allSections;

        $this->conditions = [
            'entry' => [
                'condition1' => [
                    'operand' => 'entry.section.handle == condition',
                    'name' => Craft::t('mailcraft', 'Section'),
                    'options' => $this->getEntrySections(),
                    'dependant' => true
                ],
                'condition2' => [
                    'operand' => 'entry.type.handle == condition',
                    'name' => Craft::t('mailcraft', 'Entry Type'),
                    'options' => $this->getEntryTypes()
                ],
            ],
            'user' => [
                'condition1' => [
                    'operand' => 'user.group.handle == condition',
                    'name' => Craft::t('mailcraft', 'User Group'),
                    'options' => $this->getUserGroups()
                ],
            ],
        ];
    }

    public function getConditions(): array
    {
        return $this->conditions;
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
        foreach ($this->sections as $section) {
            /** @var Section $section */

            $sectionOptions = [
                [
                    'value' => false,
                    'text' => Craft::t('mailcraft', 'Any')
                ]
            ];

            $entryTypes = Craft::$app->entries->getEntryTypesBySectionId($section->id);
            foreach ($entryTypes as $entryType) {
                /** @var EntryType $entryType */
                $sectionOptions[] = [
                    'value' => $entryType->handle,
                    'text' => $entryType->name
                ];
            }
            $options[$section->handle] = $sectionOptions;
        }
        return $options;
    }

    private function getUserGroups(): array
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