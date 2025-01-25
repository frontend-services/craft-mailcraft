<?php

namespace frontendservices\mailcraft\services;

use Craft;
use craft\base\Component;
use craft\records\Section;

class ConditionService extends Component
{
    private $conditions = [];

    public function init(): void
    {
        parent::init();

        $this->conditions = [
            'entry' => [
                'condition1' => [
                    'operand' => 'entry.section.handle == condition',
                    'name' => Craft::t('mailcraft', 'Section'),
                    'options' => $this->getEntrySections()
                ],
                'condition2' => [
                    'operand' => 'entry.type.handle == condition',
                    'name' => Craft::t('mailcraft', 'Entry Type'),
                    'options' => $this->getEntryTypes()
                ]
            ],
            'user' => [
                'condition1' => [
                    'operand' => 'user.group.handle == condition'
                ],
            ],
        ];
    }

    /**
     * @throws \JsonException
     */
    public function getJsonConditions(): string
    {
        return json_encode(self::CONDITIONS, JSON_THROW_ON_ERROR);
    }

    private function getEntrySections(): array
    {
        $sections = Section::find()->all();
        $options = [
            [
                'value' => '',
                'label' => Craft::t('mailcraft', 'Any')
            ]
        ];
        foreach ($sections as $section) {
            $options[] = [
                'value' => $section->handle,
                'label' => $section->name
            ];
        }
        return $options;
    }
}