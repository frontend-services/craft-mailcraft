<?php
/**
 * Example: a Send page audience rule that matches a Plain Text user field against a fixed list of values.
 *
 * Copy this class into your site module (or plugin), adjust FIELD_HANDLE and options(), then register it
 * in the module's init():
 *
 *     use craft\base\conditions\BaseCondition;
 *     use craft\events\RegisterConditionRulesEvent;
 *     use frontendservices\mailcraft\conditions\AudienceCondition;
 *     use yii\base\Event;
 *
 *     Event::on(
 *         AudienceCondition::class,
 *         BaseCondition::EVENT_REGISTER_CONDITION_RULES,
 *         function(RegisterConditionRulesEvent $event) {
 *             $event->conditionRules[] = FavouritePlanetConditionRule::class;
 *         }
 *     );
 *
 * The rule then shows up in the Send page's "Add a rule" menu as "Favourite planet" with the operators
 * "is one of" / "is not one of" and a multi-select of the values below.
 */

namespace modules\custommodule\conditions;

use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

class FavouritePlanetConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    /**
     * Handle of the Plain Text field on the user field layout.
     */
    private const FIELD_HANDLE = 'favouritePlanet';

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return 'Favourite planet';
    }

    /**
     * The values that can be picked. Keys are what is stored in the field, values are the labels shown.
     *
     * @inheritdoc
     */
    protected function options(): array
    {
        return [
            'mercury' => 'Mercury',
            'venus' => 'Venus',
            'mars' => 'Mars',
            'jupiter' => 'Jupiter',
        ];
    }

    /**
     * @inheritdoc
     */
    public function getExclusiveQueryParams(): array
    {
        return [self::FIELD_HANDLE];
    }

    /**
     * @inheritdoc
     */
    public function modifyQuery(ElementQueryInterface $query): void
    {
        // paramValue() turns the picked values into a Craft query param:
        // ['mars', 'venus'] for "is one of", ['not', 'mars', 'venus'] for "is not one of"
        $query->{self::FIELD_HANDLE}($this->paramValue());
    }

    /**
     * @inheritdoc
     */
    public function matchElement(ElementInterface $element): bool
    {
        return $this->matchValue($element->getFieldValue(self::FIELD_HANDLE));
    }
}
