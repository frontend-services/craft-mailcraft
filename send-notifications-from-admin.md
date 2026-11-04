# Send user notifications from Craft Admin

MailCraft -> Send messages a group of users by email and/or private message. Users need the **Send messages to users** permission. The page renders Twig you write, so grant it like **Manage email templates**, not to semi-trusted editors.

## What is sent

- **Write it here**: a subject and a message, sent as they are.
- **An email template**: any enabled template with the event **Manual send**. Only channels enabled on Email template are shown on the Send page. In the template write the recipient as `{{ recipient.email }}` (Email channel To) or `{{ recipient.id }}` (Private Message channel To), and build the text from the user profile (`{{ recipient.firstName }}`, custom fields). Email template variables `subject`, `content` and `type` will be empty, so write the real text in the template. The template's Twig condition is ignored here, the audience is always the one set on this page.

Messages are always sent through Craft's queue, so make sure it runs.

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

