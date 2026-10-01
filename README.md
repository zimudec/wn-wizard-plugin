# Winter CMS Wizard

A multi-page wizard component for [Winter CMS](https://wintercms.com): three levels of
configuration, per-step validation, and a privacy-first session store. No build step, no
jQuery, no Bootstrap — the framework serves the fingerprinted assets and the stylesheet sits
in a CSS cascade layer your theme can override.

## Requirements

- PHP 8.2 or higher
- Winter CMS 1.2 or higher

## Installation

```shell
composer require zimudec/wn-wizard-plugin
```

## A First Wizard

Add the component to a page whose URL declares an optional step parameter, then set the steps
from the Inspector:

```html
title = "Survey"
url = "/survey/:step?"
layout = "default"
==
{% component 'wizard' %}
```

With `steps = "step-1|step-2|step-3"` the wizard serves `/survey/step-1` through
`/survey/step-3`, gates the steps the user has not reached, and advances on its own.

To declare the steps, their fields and their validation rules in code instead, call `define()`
from the page. The complete example is the
[configuration quickstart](docs/configuration.md#configuration-quickstart).

## Documentation

The full documentation is indexed in [docs/documentation.md](docs/documentation.md). Every page
has a Spanish version with the same name and the `.es` suffix.

- [Installation](docs/installation.md)
- [Configuration](docs/configuration.md)
- [Validation](docs/validation.md)
- [Navigation](docs/navigation.md)
- [Session](docs/session.md)
- [Programmatic Control](docs/programmatic-control.md)
- [Customization](docs/customization.md)
- [Privacy](docs/privacy.md)
- [Accessibility](docs/accessibility.md)
- [Standards and References](docs/standards.md)
- [Upgrade Guide](docs/upgrade.md)

## Upgrading from 1.x

2.0 is a breaking release, and the `1.x` branch stays frozen for critical fixes. The
configuration, the handlers, the session key and the partials all change — see the
[upgrade guide](docs/upgrade.md) with side-by-side examples.

## Testing

```shell
php artisan winter:test -p zimudec.wizard
```

## License

MIT — see [LICENSE](LICENSE).
