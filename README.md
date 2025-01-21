# MailCraft for Craft CMS

MailCraft adds powerful transactional email capabilities to your Craft CMS site.

## Features

- Create email templates with Twig syntax
- Send emails on specific triggers (user creation, entry updates, etc.)
- Preview emails before sending
- Delayed email sending (Pro)
- Multiple recipients with CC/BCC support (Pro)
- Commerce integration (Pro)

## Requirements

This plugin requires Craft CMS 5.5.0 or later, and PHP 8.2 or later.

## Installation

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for “MailCraft”. Then press “Install”.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require frontend-services/craft-mail-craft

# tell Craft to install the plugin
./craft plugin/install mail-craft
```

## Email Template Variables

The following variables are available in all email templates:

* `{{ now }}` - Current date/time
* `{{ siteUrl }}` - Site URL
* `{{ siteName }}` - Site name

### User Events

```twig
{{ user.email }}
{{ user.username }}
{{ user.firstName }}
{{ user.lastName }}
{{ user.fullName }}
```

### Entry Events

```twig
{{ entry.title }}
{{ entry.url }}
{{ entry.section }}
{{ entry.type }}
{{ entry.customFields }}
```

### Commerce Events

```twig
{{ order.number }}
{{ order.totalPrice }}
{{ order.status }}
{{ order.customer.email }}

{% for item in order.lineItems %}
    {{ item.description }}
    {{ item.qty }}
    {{ item.price }}
{% endfor %}
```

#### Order Status Changes

```twig
{{ oldStatus.name }}
{{ newStatus.name }}
```