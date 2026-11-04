<?php

namespace frontendservices\mailcraft\conditions;

use Craft;
use craft\base\conditions\BaseElementSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;

/**
 * "Specific users": a condition rule that matches the picked users and nobody else.
 *
 * Offered by {@see AudienceCondition} on Craft 5, where the element picker takes several
 * users. Craft 4's picker takes one user per rule, so the rule is not offered there.
 */
class SpecificUsersConditionRule extends BaseElementSelectConditionRule implements ElementConditionRuleInterface
{
    /**
     * Whether this Craft version can pick several users in one rule.
     */
    public static function isAvailable(): bool
    {
        return method_exists(BaseElementSelectConditionRule::class, 'allowMultiple');
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return Craft::t('mailcraft', 'Specific users');
    }

    /**
     * @inheritdoc
     */
    protected function elementType(): string
    {
        return User::class;
    }

    /**
     * @inheritdoc
     */
    protected function allowMultiple(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    protected function criteria(): ?array
    {
        // the audience only ever holds active users
        return ['status' => User::STATUS_ACTIVE];
    }

    /**
     * @inheritdoc
     */
    public function getExclusiveQueryParams(): array
    {
        return ['id'];
    }

    /**
     * @inheritdoc
     */
    public function modifyQuery(ElementQueryInterface $query): void
    {
        $ids = $this->pickedIds();

        if ($ids === []) {
            // nobody picked matches nobody: an empty rule must not widen the audience
            $query->andWhere(['elements.id' => []]);
            return;
        }

        $query->id($ids);
    }

    /**
     * @inheritdoc
     */
    public function matchElement(ElementInterface $element): bool
    {
        return in_array((int)$element->id, $this->pickedIds(), true);
    }

    /**
     * The picked user ids (Craft 4 stores one per rule).
     *
     * @return int[]
     */
    private function pickedIds(): array
    {
        $ids = method_exists($this, 'getElementIds') ? $this->getElementIds() : $this->getElementId();

        return array_values(array_filter(array_map('intval', (array)$ids)));
    }
}
