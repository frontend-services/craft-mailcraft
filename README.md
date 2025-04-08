# MailCraft for Craft CMS

Client friendly email notifications for Craft CMS.

Send emails to content editors when new entries are created. Send 
email to user 7 days after the registration to ask for a feedback. 
And many many more. 

## Features

- Create email templates with Twig syntax
- Send emails on specific triggers (user creation, entry updates, etc.)
- Delayed email sending

## Coming soon
 
- Preview emails
- Craft Commerce Emails

## Requirements

This plugin requires Craft CMS 5.5.0 or later, and PHP 8.2 or later.

## Recipes

### Send an email 7 days after user registration
```
Event: User is Activated
Delay (s): 604800
```

### Send an email only to users registered with @company.com
```
Event: User is Created
Extra Conditions: user.email ends with "@company.com"
```

## Feature Requests

Have an idea for a new trigger or feature? We welcome your suggestions!

Feel free to:
- Open an issue on [GitHub](https://github.com/frontend-services/craft-mailcraft/issues)
- Contact us directly at [mato@frontend.services](mailto:mato@frontend.services)
- Submit a pull request if you've implemented something you think would benefit others

We're actively developing MailCraft and value community input on which features to prioritize.