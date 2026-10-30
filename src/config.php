<?php
/**
 * MailCraft config file
 *
 * Copy this file to your project's `config/` folder as `mailcraft.php` and
 * change the values you need.
 *
 * Every value below is the plugin default. Any setting defined there overrides
 * the value saved in the control panel (Settings -> MailCraft) and can no longer
 * be changed there, so delete the lines you want to keep editable in the CP.
 *
 * Multi-environment configs are supported, e.g.:
 *   return ['*' => [...], 'dev' => [...], 'production' => [...]];
 */

return [
    // Plugin name shown in the control panel navigation
    'pluginName' => 'MailCraft',

    // Use CKEditor for the template body editor (only if the CKEditor plugin is installed)
    'useWysiwyg' => true,

    // Start the "CC, BCC, Reply-To" section of the template editor expanded
    // (it always opens when a template already has one of them filled in)
    'showCcBcc' => false,

    // Send emails with the "Instantly" delay during the triggering request instead of
    // pushing them to the queue. Delayed emails always go through the queue.
    'sendInstantEmailsDirectly' => false,

    // Delivery channels templates may use. Built-in: 'email', 'privateMessage'.
    // At least one channel is required.
    'allowedChannels' => ['email'],

    // Global settings per channel, keyed by channel handle: ['handle' => [...]].
    // The built-in channels need none; used by custom channels (e.g. a webhook URL).
    'channelSettings' => [],

    // Channels whose sends are recorded on the Status page, e.g. ['email'],
    // or ['*'] for every channel. Empty = nothing is recorded.
    'statusChannels' => [],

    // Days to keep sent/failed status records after their last update. 0 = keep forever.
    'statusRetentionDays' => 30,

    // --- Private messaging -------------------------------------------------

    // Labels for the message `type` value, as 'Label' => 'value'.
    // A plain list (['order', 'support']) also works; types without a label show the raw value.
    'privateMessageTypes' => [],
    // 'privateMessageTypes' => ['User message' => 'user', 'System message' => 'system', 'Info' => 'info'],

    // Template used by the send() facade, by ID or handle.
    // null = the built-in simple message.
    'privateMessageTemplate' => null,

    // Let logged-in users send messages via the `mailcraft/messages/send` action
    'allowFrontendSending' => false,

    // Max messages per user per hour through the frontend action. 0 = no limit.
    'frontendSendingPerHour' => 30,

    // Type written on messages sent through the frontend action (null = no type)
    'frontendSendingType' => 'user',
];
