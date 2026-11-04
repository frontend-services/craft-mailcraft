# Send user notifications from Craft Admin

MailCraft -> Send messages a group of users by email and/or private message. Users need the **Send messages to users** permission. The page renders Twig you write, so grant it like **Manage email templates**, not to semi-trusted editors.

## What is sent

- **Write it here**: a subject and a message, sent as they are.
- **An email template**: any enabled template with the event **Manual send**. Only channels enabled on Email template are shown on the Send page. In the template write the recipient as `{{ recipient.email }}` (Email channel To) or `{{ recipient.id }}` (Private Message channel To), and build the text from the user profile (`{{ recipient.firstName }}`, custom fields). Email template variables `subject`, `content` and `type` will be empty, so write the real text in the template. The template's Twig condition is ignored here, the audience is always the one set on this page.

Messages are always sent through Craft's queue, so make sure it runs.

### Your own rules

When Craft's rules are not enough you can add a rule of your own. MailCraft's audience condition is a Craft condition, so Craft's `registerConditionRules` event works on it.

1. Write a rule class. You can find example rule in [examples/conditions/FavouritePlanetConditionRule.php](./examples/conditions/FavouritePlanetConditionRule.php) - it extends Craft's `BaseMultiSelectConditionRule` (operators "is one of" / "is not one of" with a multi-select of fixed values) and filters the user query by a Plain Text field.
2. Register it in your module's `init()`:

```php
use craft\base\conditions\BaseCondition;
use craft\events\RegisterConditionRulesEvent;
use frontendservices\mailcraft\conditions\AudienceCondition;
use modules\custommodule\conditions\FavouritePlanetConditionRule;
use yii\base\Event;

Event::on(
    AudienceCondition::class,
    BaseCondition::EVENT_REGISTER_CONDITION_RULES,
    function(RegisterConditionRulesEvent $event) {
        $event->conditionRules[] = FavouritePlanetConditionRule::class;
    }
);
```

The rule then appears in the Send page's "Add a rule" menu under its label. Any class that implements `craft\elements\conditions\ElementConditionRuleInterface` works. Craft's base classes (`BaseTextConditionRule`, `BaseMultiSelectConditionRule`, `BaseSelectConditionRule`, `BaseDateRangeConditionRule`, `BaseElementSelectConditionRule`) cover the usual inputs.

A rule is saved with each send by class name. If the class is later removed or its field deleted, the Send page shows the saved conditions as unloadable and refuses to send until they are removed, so an audience is never silently widened. The same rules can be passed from code, see below.

## From code

```php
use Craft;
use craft\elements\conditions\DateCreatedConditionRule;
use craft\elements\conditions\users\AdminConditionRule;
use craft\elements\conditions\users\EmailConditionRule;
use craft\elements\conditions\users\GroupConditionRule;
use craft\elements\conditions\users\LastLoginDateConditionRule;
use craft\fields\conditions\LightswitchFieldConditionRule;
use craft\fields\conditions\OptionsFieldConditionRule;
use frontendservices\mailcraft\conditions\SpecificUsersConditionRule;
use frontendservices\mailcraft\MailCraft;

// Members who have not logged in for 30 days (rules are ANDed: in the group AND last login before 30 days ago)
$audience = [
    'condition' => [
        'conditionRules' => [
            [
                'class' => GroupConditionRule::class,
                'operator' => 'in',
                'values' => [$membersGroupUid], // craft\records\UserGroup uid
            ],
            [
                'class' => LastLoginDateConditionRule::class,
                'rangeType' => 'before',
                'periodType' => 'daysAgo',
                'periodValue' => 30,
            ],
        ],
    ],
];

// Members who signed up but never logged in (an activation nudge): same group rule, no login date
// [
//     ['class' => GroupConditionRule::class, 'operator' => 'in', 'values' => [$membersGroupUid]],
//     ['class' => LastLoginDateConditionRule::class, 'rangeType' => 'empty'],
// ]

// New users of the past 7 days (a welcome follow-up)
// [
//     ['class' => DateCreatedConditionRule::class, 'rangeType' => 'past7Days'],
// ]

// Active in the past 90 days (a product announcement to people who still come back)
// [
//     ['class' => LastLoginDateConditionRule::class, 'rangeType' => 'past90Days'],
// ]

// Colleagues: every account on the company domain
// [
//     ['class' => EmailConditionRule::class, 'operator' => 'ew', 'value' => '@example.com'],
// ]


// Newsletter opt-in, a Lightswitch field on the user layout
// Custom fields are addressed by uid: $optInFieldUid = Craft::$app->getFields()->getFieldByHandle('newsletterOptIn')->uid;
// [
//     ['class' => LightswitchFieldConditionRule::class, 'fieldUid' => $optInFieldUid, 'value' => true],
// ]

// Interested in a topic, a Dropdown/Checkboxes field on the user layout (option values)
// [
//     ['class' => OptionsFieldConditionRule::class, 'fieldUid' => $topicsFieldUid, 'operator' => 'in', 'values' => ['events', 'news']],
// ]

// Admins only
// [
//     ['class' => AdminConditionRule::class, 'value' => true],
// ]

// Three specific people (Craft 5 only)
// [
//     ['class' => SpecificUsersConditionRule::class, 'elementIds' => [14, 59, 103]],
// ]

$send = MailCraft::getInstance()->sends->queue(
    $audience,
    'octoberNews',                   // template id or handle (enabled, event "Manual send")
    ['email', 'privateMessage'],     // channels enabled on the template
    Craft::$app->getUser()->getId()  // sender, optional (null from console)
);

if ($send === null) {
    // the reason: no matching user, template not enabled, …
    Craft::warning(MailCraft::getInstance()->sends->getLastError(), __METHOD__);
}
```

