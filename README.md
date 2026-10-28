# MailCraft for Craft CMS

> **Client friendly email notifications**

MailCraft allows content managers to set up email notifications directly from the Craft CMS control 
panel - no developer assistance required. Install once, and let your clients handle the rest.

![MailCraft Logo](./assets/screenshot.png)

## What's New 🚀

- **Statuses**: List all sent notifications and their delivery status in the admin interface

## Key Features

- **Visual Email Builder**: Create beautiful email templates using familiar Twig syntax
- **Event-Driven Triggers**: Configure emails to send automatically based on specific system events
- **Scheduled Delivery**: Set up delayed emails for follow-ups, reminders, and nurture campaigns
- **Conditional Logic**: Target specific users or content with powerful filtering options
- **Zero Code Required**: All configuration happens in the admin interface

## Event Triggers & Notification Types

MailCraft supports various event types to help you automate your notifications:

### Content Events
- **Entry Created** - Send notifications when new content is published
- **Entry Updated** - Alert team members when existing content changes
- **Entry Deleted** - Notify when content is removed
- **Filtering** - Target by section, entry type, status, field values, and more

### Asset Events
- **Asset Uploaded** - Notify when a new file is added to a volume
- **Asset Updated** - React to file changes, including title, alt text, focal point adjustments, image edits, and custom field updates. Email includes a list of what changed
- **Asset Deleted** - Notify when a file is removed from a volume

### User Management
- **User Created** - Welcome new registrations automatically or notify admins
- **User Updated** - React to profile changes or important user updates
- **User Activated** - Send onboarding materials when accounts become active
- **User Verified Email** - Acknowledge successful verification steps
- **User Deleted** - Notify when a user account is removed
- **Filtering** - Target by user group, custom fields, and more

### E-Commerce
- **Order Created** - Notify users when they place an order or alert admins
- **Order Status Changed** - Alert users when their order status changes

### System Notifications
- **Updates Available** - Get notified when a new version of Craft CMS or 
plugins is available. Optionally trigger only on critical updates
- **Failed Queue Job** - Notify admins when a queue job fails

### Missing an Event?

If you need a specific event that isn't currently supported, please [open an issue on GitHub](https://github.com/frontend-services/craft-mailcraft/issues).

## Custom Email Design

Want more control over the look and feel of your emails? Use our **[Email Templates](https://plugins.craftcms.com/email-templates)** plugin alongside MailCraft to design beautiful, reusable email layouts — no coding required.

## Feature Requests & Contributions

We're actively developing MailCraft and welcome community input:

- **Issues & Requests**: [Open an issue on GitHub](https://github.com/frontend-services/craft-mailcraft/issues)
- **Contribute**: Pull requests welcome for features that benefit the community

## Roadmap

- More Event Types
- Twig variable suggestions
- Private messaging system (currently in development)