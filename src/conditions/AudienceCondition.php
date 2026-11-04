<?php

namespace frontendservices\mailcraft\conditions;

use craft\elements\conditions\users\UserCondition;

/**
 * The user condition behind the Send page's audience. Craft's user condition plus the rules
 * MailCraft adds.
 */
class AudienceCondition extends UserCondition
{
    /**
     * @inheritdoc
     */
    protected function selectableConditionRules(): array
    {
        $types = parent::selectableConditionRules();

        if (SpecificUsersConditionRule::isAvailable()) {
            $types[] = SpecificUsersConditionRule::class;
        }

        return $types;
    }
}
