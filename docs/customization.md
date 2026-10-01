# Customization

- [Introduction](#introduction)
- [Styling](#styling)
    - [The Cascade Layer](#the-cascade-layer)
    - [Design Tokens](#design-tokens)
    - [Unloading the Stylesheet](#unloading-the-stylesheet)
- [The Script](#the-script)
    - [Focus Management](#focus-management)
    - [Form-level Errors](#form-level-errors)
    - [The Double-submission Guard](#the-double-submission-guard)
    - [Replacing the Script Behavior](#replacing-the-script-behavior)
- [Assets](#assets)
- [View Overrides](#view-overrides)
    - [View Variables](#view-variables)
    - [Adding a Field Type](#adding-a-field-type)
- [Write a Custom Step Partial](#write-a-custom-step-partial)
- [Customize the Texts](#customize-the-texts)
    - [Per-step Messages](#per-step-messages)
    - [Application Language Overrides](#application-language-overrides)

<a name="introduction"></a>
## Introduction

Everything the plugin renders is designed to be replaced: the stylesheet sits in a cascade
layer, the script listens to DOM events instead of owning them, every partial has a theme
override, and every string comes from a language file.

This page covers the four surfaces: the stylesheet, the script, the views, and the texts.

<a name="styling"></a>
## Styling

The plugin ships `assets/css/wizard.css` with a self-contained default look — a tab-style
step indicator, labeled fields, inline errors, right-aligned actions. It requires no CSS
framework, and it adapts to `prefers-color-scheme: dark` and `prefers-reduced-motion`.

Your layout needs the `{% styles %}` tag for the component to inject it.

<a name="the-cascade-layer"></a>
### The Cascade Layer

Every rule of the stylesheet lives inside `@layer wizard`. A stylesheet of your theme is
unlayered by default, and unlayered styles always beat layered ones, so your rules win without
`!important` and without a specificity contest.

<a name="design-tokens"></a>
### Design Tokens

The look is driven by the `--wizard-*` custom properties. Redeclare them anywhere in your
theme to retune it:

```css
.wizard {
    --wizard-accent: #7c3aed;
    --wizard-radius: 0.75rem;
}
```

<a name="unloading-the-stylesheet"></a>
### Unloading the Stylesheet

With `css = 0` in the Inspector the component injects nothing, and your theme provides all the
styling over the same semantic markup. The class names are stable and documented by the
markup below.

<a name="the-script"></a>
## The Script

The component loads `assets/js/wizard.js`, a dependency-free script with three jobs. It
listens to the DOM events Snowboard's request layer dispatches on the form, so it works
regardless of how the theme initialises the Snowboard singleton — and it does nothing at all
without JavaScript, because the same markup is a working form without it.

<a name="focus-management"></a>
### Focus Management

When an AJAX submit fails validation, the script marks every invalid control with
`aria-invalid="true"` and moves focus to the first one. A submit that starts clears the marks
of the previous attempt.

The fields it looks up are found by id: the script queries `#` plus the error key, escaped
with `CSS.escape()`. That is why the input `id` must equal the error key the server returns —
see [Write a Custom Step Partial](#write-a-custom-step-partial).

<a name="form-level-errors"></a>
### Form-level Errors

An error with no matching input has no field bag for Snowboard to paint into. The wizard's
shell therefore includes a form-level container:

```html
<div class="wizard__form-error" data-wizard-errors role="alert" tabindex="-1" hidden>
    <p class="wizard__form-error__title">Fix the following errors:</p>
    <div class="wizard__form-error__list" data-wizard-errors-list></div>
</div>
```

The script collects every message whose key matches no `[data-validate-error]` element and
renders it there, then unhides the container and focuses it when no field error applies.

> [!NOTE]
> The container is present from the page load. The per-field bags are not: Snowboard's
> `FormValidation` removes every `[data-validate-error]` element at startup and leaves a
> comment placeholder, re-inserting the empty element only when a message arrives. A
> `role="alert"` on a region that is added to the DOM with its message still announces in
> practice, but the technique [ARIA19](https://www.w3.org/WAI/WCAG22/Techniques/aria/ARIA19)
> asks for the region to exist from the start, and only the form-level container does. See
> [Error Association and Summary](standards.md#error-association-and-summary).

Error keys beginning with an underscore are never rendered. This is a client-side convention
for your own handlers — a control channel, not something the plugin produces:

```php
// A handler of your own may return an underscore-prefixed key to pass a value
// to the client without showing it.
return ['_retry_after' => 30];
```

<a name="the-double-submission-guard"></a>
### The Double-submission Guard

While a submit is in flight, the form carries `aria-busy="true"` and a `data-wizard-submitting`
attribute, and any further submit of the same form is cancelled in the capture phase — before
Snowboard's delegated window-level submit handler can create a second request. The submit
button is never disabled.

The lock is released on `ajaxDone`, `ajaxFail` and `ajaxAlways`, so every exit path frees the
form, including a cancelled request, which fires only `ajaxAlways`. A guard that released on
`ajaxDone` and `ajaxFail` alone would leave the form permanently unable to submit.

The visual loading state is left to the theme, through the `data-attach-loading` attribute the
submit button carries.

<a name="replacing-the-script-behavior"></a>
### Replacing the Script Behavior

Set `js = 0` to unload the script and implement the behaviour yourself over the same DOM
events:

```js
document.querySelectorAll('form[data-request-validate]').forEach((form) => {
    form.addEventListener('ajaxFail', (event) => {
        const fields = (event.request.responseData || {}).X_WINTER_ERROR_FIELDS || {};
        // Your focus strategy: the first invalid field, an error summary, …
    });
});
```

To extend the built-in behaviour rather than replace it, keep `js` on and add your own
listeners. The script only sets `aria-invalid` and moves focus, so extra listeners never
conflict with it.

<a name="assets"></a>
## Assets

There is no build step and no version query. The framework Asset Combiner fingerprints the
combined URL with a content hash and modification time, serves it with a one-year cache
header, and minifies it in production when debug mode is off. The script is served with
`defer`, so it never blocks HTML parsing.

Both assets are opt-out from the Inspector:

| Property | Default | Effect of turning it off |
|---|---|---|
| `css` | `true` | Nothing is injected; your theme styles the same markup |
| `js` | `true` | No focus management, no form-level errors, no double-submission guard |

<a name="view-overrides"></a>
## View Overrides

Place a partial with the same name under `partials/wizard/` of your theme. The plugin is not
modified, and the theme's version wins.

| Partial | Renders |
|---|---|
| `partials/wizard/default.htm` | The step shell: indicator, form, fields, buttons |
| `partials/wizard/nav.htm` | The step indicator |
| `partials/wizard/buttons.htm` | The navigation buttons |
| `partials/wizard/field.htm` | The field dispatcher |
| `partials/wizard/field_text.htm` | A text field |
| `partials/wizard/field_select.htm` | A select field |

A step can also replace the shell entirely with `->view('my-partial')`. That partial renders
instead of `default.htm`, inside the same `wizard` wrapper the shell uses:

```php
->step('beneficiaries', 'Beneficiaries')->view('buy/beneficiaries')->end()
```

<a name="view-variables"></a>
### View Variables

Inside the plugin's own partials you have the page variables plus these:

| Variable | What it holds |
|---|---|
| `{{ __SELF__ }}` | The component instance |
| `{{ __SELF__.currentStepFields() }}` | The fields of the current step |
| `{{ wizard.steps }}` | The step list: `code`, `title`, `url`, `state` |
| `{{ wizard.stepCurrentName }}` | The title of the current step |
| `{{ wizard.stepNumber }}` | The 1-based position of the current step |
| `{{ wizard.urls }}` | `next`, `prev` and `finish` URLs |
| `{{ wizard.data }}` | The accumulated data of the wizard |
| `{{ wizard.retainData }}` | Whether the completed steps are clickable |

`wizard.data` holds user data only; the wizard's internal metadata never reaches a template.

The WAI multi-page forms tutorial asks for the progress first in the page title, followed by
the step name. The layout provides both values:

```html
<title>{% put title %}Step {{ wizard.stepNumber }} of {{ wizard.steps|length }}: {{ wizard.stepCurrentName }}{% endput %}</title>
```

<a name="adding-a-field-type"></a>
### Adding a Field Type

The dispatcher decides which partial renders a field, and it knows two types. To add a third
you must override **both** the dispatcher and the new partial — a `field_checkbox.htm` on its
own is never reached:

```html
{# themes/<theme>/partials/wizard/field.htm #}
{% if field.type == 'select' %}
    {% partial '@field_select.htm' field=field value=value %}
{% elseif field.type == 'checkbox' %}
    {% partial '@field_checkbox.htm' field=field value=value %}
{% else %}
    {% partial '@field_text.htm' field=field value=value %}
{% endif %}
```

See [Field Types](configuration.md#field-types) for what a declared but unknown type does
today.

<a name="write-a-custom-step-partial"></a>
## Write a Custom Step Partial

A step that needs markup the generic shell cannot draw declares `->view('my/partial')`. That
partial replaces the whole step shell, so it also owns the form, the buttons and the step
indicator.

Five properties of such a partial are not obvious.

**`__SELF__` is empty in a theme partial.** It is defined inside the component's own partials
only. Use the component alias the page declares, so the form asks for the handler literally as
`wizard::onNext`; otherwise the browser rejects the malformed selector.

**The input `id` must equal the error key the server returns.** The focus script looks the
field up as `#` plus that key, and the error bags match `data-validate-for` against the same
keys. For a grid posted as `items[0][sku]`, the key is `items.0.sku`:

```html
<input type="text" name="items[0][sku]" id="items.0.sku" data-validate-error="items.0.sku">
```

Matching the id to the name instead leaves every bag unable to find its field, and the focus
script unable to find the input.

**Read the data from `wizard.data`, never from a page method.** On a full render `wizard` is
the array the component exposes; on a partial re-render it is the component object. A method
call such as `this.rows()` returns empty in that second context without raising anything.
`{{ wizard.data|default({}) }}` covers both.

**Use `??` for values and `|default` for booleans.** The `default` filter also fires on the
empty string, so a field the user just cleared would spring back to the value the previous
submit stored. `??` only falls through when the key is absent.

**A two-variable `for` needs a map, not a list of pairs.**
`{% for key, label in [('a', 'One')] %}` is a syntax error;
`{% for key, label in {'a': 'One'} %}` iterates correctly.

<a name="customize-the-texts"></a>
## Customize the Texts

Every text the wizard shows is replaceable, validation messages included. Two mechanisms
exist, from the most precise to the broadest.

<a name="per-step-messages"></a>
### Per-step Messages

Pass them to the builder with the `field.rule` syntax. They apply to that step and take
precedence over every other source:

```php
->rules(['field1' => 'required', 'field2' => 'required|email'])
->messages([
    'field1.required' => 'Enter your full name',
    'field2.email'    => 'Enter a valid email address',
])
```

<a name="application-language-overrides"></a>
### Application Language Overrides

Winter resolves the generic validation messages from the `system` namespace, so you can
override them for the whole application from the `lang/` directory without touching the
plugin. To change every `required` message, create `lang/en/system/validation.php`:

```php
<?php

return [
    'required' => 'This field is required',
    'attributes' => [
        'field1' => 'your full name', // replaces :attribute in the messages
    ],
];
```

The same structure overrides the plugin's own strings. Create
`lang/en/zimudec/wizard/lang.php` and include only the keys you want to change — the original
file provides the rest:

```php
<?php

return [
    'buttons' => [
        'next' => 'Continue',
    ],
    'nav' => [
        'steps' => 'Progress',
    ],
];
```

The plugin's own strings ship in English, Spanish and French.

> [!TIP]
> A message that says what to do — "Enter your full name" — tells the user how to fix the
> problem, not only that there is one. Prefer specific texts for the fields your users
> struggle with; see [Accessibility](accessibility.md).
