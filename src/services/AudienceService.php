<?php

namespace frontendservices\mailcraft\services;

use Craft;
use craft\base\Component;
use craft\elements\db\UserQuery;
use craft\elements\User;
use frontendservices\mailcraft\conditions\AudienceCondition;
use InvalidArgumentException;
use Throwable;

/**
 * Turns a stored audience into a user query.
 *
 * The stored shape is `['condition' => ?array]` (an {@see AudienceCondition} config: Craft's user condition plus MailCraft's own rules).
 * Parts that cannot be resolved (a condition rule Craft cannot load) are kept and reported by problems(). 
 * query() refuses to run while there are any, so an audience is never silently widened.
 */
class AudienceService extends Component
{
    /**
     * Brings any input (posted form data, a JSON string, stored config) into the stored shape.
     * Rules Craft cannot load are kept so problems() can report them.
     *
     * @param mixed $value
     * @return array{condition: array|null}
     */
    public function normalize(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            $value = [];
        }

        $condition = null;
        $rules = $this->conditionRulesFrom($value);
        if ($rules) {
            $config = null;
            try {
                $userCondition = $this->newCondition();
                $userCondition->setConditionRules($rules);
                $config = $userCondition->getConfig();
            } catch (Throwable $e) {
                $config = null;
            }
            // Craft drops rules it cannot create (e.g. a field that does not exist on this site). 
            // Dropping one would widen the audience, so the rules are then kept as given and problems() reports them
            if ($config === null || count($config['conditionRules'] ?? []) < count($rules)) {
                $config = ['class' => AudienceCondition::class, 'conditionRules' => $rules];
            }
            $condition = $config;
        }

        return [
            'condition' => $condition,
        ];
    }

    /**
     * The condition rules in an audience input, before Craft loads them.
     *
     * Only array entries are kept. The form's `conditionJson` carries the stored rules that could not be loaded into the builder. 
     * They are added to the posted rules unless `dropCondition` is ticked, so adding a rule never removes them.
     *
     * @param mixed $value Audience input (posted form data or stored config)
     * @return array[]
     */
    public function conditionRulesFrom(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $condition = $value['condition'] ?? null;
        $rules = is_array($condition) ? ($condition['conditionRules'] ?? null) : null;
        $rules = is_array($rules) ? array_values(array_filter($rules, 'is_array')) : [];
        if (!empty($value['dropCondition'])) {
            return $rules;
        }

        $json = $value['conditionJson'] ?? null;
        $carried = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        $carried = is_array($carried) ? ($carried['conditionRules'] ?? null) : null;
        $carried = is_array($carried) ? array_values(array_filter($carried, 'is_array')) : [];

        return array_merge($rules, $carried);
    }

    /**
     * Splits stored condition rules into those the builder can show and those it cannot.
     *
     * @param array[] $rules
     * @return array{loadable: array[], unloadable: array[]}
     */
    public function partitionRules(array $rules): array
    {
        $loadable = [];
        $unloadable = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if ($this->conditionLoads(['conditionRules' => [$rule]])) {
                $loadable[] = $rule;
            } else {
                $unloadable[] = $rule;
            }
        }

        if ($loadable && !$this->conditionLoads(['conditionRules' => $loadable])) {
            return ['loadable' => [], 'unloadable' => array_merge($loadable, $unloadable)];
        }
        if (!$unloadable && $loadable && !$this->conditionLoads(['conditionRules' => $rules])) {
            return ['loadable' => [], 'unloadable' => $loadable];
        }

        return ['loadable' => $loadable, 'unloadable' => $unloadable];
    }

    /**
     * Whether the stored condition loads with every rule (true when it has no rules).
     */
    public function conditionLoads(?array $condition): bool
    {
        if (empty($condition['conditionRules'])) {
            return true;
        }

        try {
            $this->createCondition($condition);
        } catch (Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * Whether the audience has no condition (audience is everyone).
     */
    public function isEmpty(array $audience): bool
    {
        return empty($audience['condition']);
    }

    /**
     * Translated descriptions of every part of the audience that cannot be resolved.
     *
     * @return string[] Empty when everything resolves
     */
    public function problems(array $audience): array
    {
        $problems = [];

        if (!empty($audience['condition'])) {
            try {
                $this->createCondition($audience['condition']);
            } catch (Throwable $e) {
                $problems[] = Craft::t('mailcraft', 'The saved conditions could not be loaded: {error}.', ['error' => $e->getMessage()]);
            }
        }

        return $problems;
    }

    /**
     * The active users in the audience, ordered by id.
     *
     * @throws InvalidArgumentException when problems() reports anything
     */
    public function query(array $audience): UserQuery
    {
        $problems = $this->problems($audience);
        if ($problems) {
            throw new InvalidArgumentException(implode(' ', $problems));
        }

        $query = User::find()
            ->status(User::STATUS_ACTIVE)
            ->orderBy(['elements.id' => SORT_ASC]);

        if (!empty($audience['condition'])) {
            $conditionQuery = User::find();
            $this->createCondition($audience['condition'])->modifyQuery($conditionQuery);
            $query->andWhere(['elements.id' => $conditionQuery->select(['elements.id'])]);
        }

        return $query;
    }

    /**
     * The number of active users in the audience.
     *
     * @throws InvalidArgumentException when problems() reports anything
     */
    public function count(array $audience): int
    {
        return (int)$this->query($audience)->count();
    }

    /**
     * A one-line human description of the audience.
     */
    public function describe(array $audience): string
    {
        if ($this->isEmpty($audience)) {
            return Craft::t('mailcraft', 'All users');
        }

        $parts = [];

        if (!empty($audience['condition'])) {
            $parts[] = Craft::t('mailcraft', '{n} condition(s)', [
                'n' => count($audience['condition']['conditionRules'] ?? []),
            ]);
        }

        return implode(' · ', $parts);
    }

    /**
     * The user condition builder for the stored condition config.
     */
    public function getBuilderHtml(?array $condition, string $name, string $id): string
    {
        $rules = $condition['conditionRules'] ?? [];
        if (!is_array($rules)) {
            $rules = [];
        }

        if ($rules) {
            try {
                $this->createCondition(['conditionRules' => $rules]);
            } catch (Throwable $e) {
                $rules = [];
            }
        }

        try {
            return $this->renderBuilder($rules, $name, $id);
        } catch (Throwable $e) {
            if (!$rules) {
                throw $e;
            }
            Craft::warning('MailCraft could not render the saved audience conditions: ' . $e->getMessage(), __METHOD__);
            return $this->renderBuilder([], $name, $id);
        }
    }

    private function renderBuilder(array $rules, string $name, string $id): string
    {
        $userCondition = $this->newCondition();
        if ($rules) {
            $userCondition->setConditionRules($rules);
        }
        $userCondition->mainTag = 'div';
        $userCondition->id = $id;
        $userCondition->name = $name;

        return $userCondition->getBuilderHtml();
    }

    /**
     * Rebuilds a stored condition.
     *
     * @throws Throwable
     */
    private function createCondition(array $config): AudienceCondition
    {
        $rules = $config['conditionRules'] ?? [];
        if (!is_array($rules)) {
            throw new InvalidArgumentException('conditionRules is not a list');
        }

        $condition = $this->newCondition();
        $condition->setConditionRules($rules);

        // a dropped rule (e.g. its field was deleted) would widen the audience
        if (count($condition->getConditionRules()) !== count($rules)) {
            throw new InvalidArgumentException(Craft::t('mailcraft', 'a rule is no longer available'));
        }

        // a kept rule can still point at a field that is gone (another field shares its rule class)
        $condition->modifyQuery(User::find());

        return $condition;
    }

    /**
     * An empty audience condition (the stored class is never used; the rules are what matters).
     */
    private function newCondition(): AudienceCondition
    {
        /** @var AudienceCondition $condition */
        $condition = Craft::createObject(AudienceCondition::class, [User::class]);

        return $condition;
    }
}
