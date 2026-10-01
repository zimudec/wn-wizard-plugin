# Wizard Documentation

The wizard has three levels of configuration. Level 0 configures a linear walk from the
Inspector with no code. Level 1 declares the steps, their fields and their rules from the
page. Level 2 adds hooks on the same submit pipeline. Every level runs on one core, so what
you learn at level 0 still holds at level 2.

Read these pages in order the first time. Each one stands on its own afterwards, and the
complete checkout in [Configuration](configuration.md#configuration-quickstart) is the example
the rest of the documentation extends.

**Prologue**

- [Upgrade Guide](upgrade.md) — moving an existing wizard from 1.x to 2.0

**Getting Started**

- [Installation](installation.md) — requirements, the Composer package, and the page URL the wizard needs
- [Configuration](configuration.md) — the three levels, the component properties, and the builder reference

**The Basics**

- [Validation](validation.md) — rules, messages, extra validation, revalidation, the commit hook, and the events
- [Navigation](navigation.md) — how a step is resolved from the URL, how access is gated, and how the flow advances
- [Session](session.md) — what the wizard stores, for how long, and how to read and write it

**Digging Deeper**

- [Programmatic Control](programmatic-control.md) — AJAX handlers of your own: lookups, carts, guards, conditional skips
- [Customization](customization.md) — styling, assets, view overrides, and texts

**Security**

- [Privacy](privacy.md) — what the wizard does with the data, and the checklist to confirm before you publish
- [Accessibility](accessibility.md) — what ships with the component, and what only you can provide

**Reference**

- [Standards and References](standards.md) — the standard behind each behavior, with its citation
- [Changelog](../CHANGELOG.md) — every released change

<a name="api-index"></a>
## API Index

Every public surface of the plugin, and the page that documents it.

| Surface | Reference |
|---|---|
| Component properties (11) | [Component Properties](configuration.md#component-properties) |
| Wizard-wide builder methods (6) | [Wizard-wide Settings](configuration.md#wizard-wide-settings) |
| Step builder methods (11) | [Step Methods](validation.md#step-methods) |
| Session API (7 calls) | [Session API](session.md#session-api) |
| Navigation API (2 calls) | [Navigation API](navigation.md#navigation-api) |
| Cycle events (3) | [Cycle Events](validation.md#cycle-events) |
| View variables (6) | [View Variables](customization.md#view-variables) |
| Partials (6) | [View Overrides](customization.md#view-overrides) |
| Configuration file (1 key) | [Global Defaults](installation.md#global-defaults) |
| Translated exceptions (6) | [Configuration Errors](configuration.md#configuration-errors) |
