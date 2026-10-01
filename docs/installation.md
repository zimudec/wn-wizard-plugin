# Installation

- [Introduction](#introduction)
- [Requirements](#requirements)
- [Installing the Plugin](#installing-the-plugin)
- [Preparing the Page](#preparing-the-page)
    - [Declaring the Step Parameter](#declaring-the-step-parameter)
- [Global Defaults](#global-defaults)
- [Running the Tests](#running-the-tests)

<a name="introduction"></a>
## Introduction

The wizard is a Winter CMS plugin with a single component. There is no build step, no
published assets directory and no JavaScript bundler configuration: the component injects its
own stylesheet and script through the framework Asset Combiner, and both are opt-out.

<a name="requirements"></a>
## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.2 or higher |
| Winter CMS | 1.2 or higher |

Winter CMS 1.2 ships Snowboard, which the wizard's script listens to. The plugin does not
support the jQuery era of Winter 1.0.

<a name="installing-the-plugin"></a>
## Installing the Plugin

Require the package with Composer:

```shell
composer require zimudec/wn-wizard-plugin
```

The plugin registers itself as `zimudec.wizard` and exposes one component alias, `wizard`.

<a name="preparing-the-page"></a>
## Preparing the Page

Add the component to any CMS page:

```html
title = "Survey"
url = "/survey/:step?"
layout = "default"
==
{% component 'wizard' %}
```

Configure the steps from the Inspector. At level 0 the component needs nothing else to render
and advance a linear walk of steps — see [Configuration](configuration.md#level-0-properties-only).

<a name="declaring-the-step-parameter"></a>
### Declaring the Step Parameter

The page URL **must** declare an optional `step` parameter, as in `/survey/:step?` above. The
wizard resolves the current step from that parameter, and it builds every navigation URL from
the same page.

A page without `:step?` throws a translated `ConfigurationException` on the first request:

> The page URL must declare an optional step parameter (e.g. "/:step?") for the wizard to work. Add ":step?" at the end of the page URL.

This is deliberate. A wizard whose steps are not addressable cannot be linked to, resumed
after an expired session, or guarded server-side.

<a name="global-defaults"></a>
## Global Defaults

The plugin ships one configuration key, the default inactivity timeout in minutes:

```php
<?php
// plugins/zimudec/wizard/config/config.php

return [
    'ttl' => 30,
];
```

To change the default for the whole application, create the same key in the application's
configuration directory instead of editing the package file. Winter CMS cascades the
application value over the plugin value, and your file survives a package update:

```php
<?php
// config/zimudec/wizard.php

return [
    'ttl' => 15,
];
```

You may also scope the default to a single environment with `config/<env>/zimudec/wizard.php`.

> [!NOTE]
> This default applies only when a wizard leaves the TTL undeclared. The component property
> `ttl` and the builder method `->ttl()` both override it per wizard — see
> [Wizard-wide Settings](configuration.md#wizard-wide-settings).

<a name="running-the-tests"></a>
## Running the Tests

The plugin ships a test suite covering the three configuration levels:

```shell
php artisan winter:test -p zimudec.wizard
```

Next, continue to [Configuration](configuration.md).
