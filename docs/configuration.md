# Configuration

- [Introduction](#introduction)
- [Configuration Quickstart](#configuration-quickstart)
    - [The Checkout Page](#the-checkout-page)
    - [What Ends Up in the Session](#what-ends-up-in-the-session)
- [Level 0: Properties Only](#level-0-properties-only)
- [Component Properties](#component-properties)
    - [The List Syntax](#the-list-syntax)
- [Level 1: The Builder](#level-1-the-builder)
- [Level 2: Hooks](#level-2-hooks)
- [Wizard-wide Settings](#wizard-wide-settings)
    - [Precedence](#precedence)
- [Fields](#fields)
    - [Field Keys](#field-keys)
    - [Field Types](#field-types)
- [Configuration Errors](#configuration-errors)

<a name="introduction"></a>
## Introduction

A wizard is a list of steps, a submit pipeline that validates and advances them, and a
session store that holds what the user entered. You configure the list of steps in one of
three ways, and the other two halves of the wizard work the same in all of them.

Level 0 sets the steps from the Inspector and writes no code. Level 1 declares the steps,
their fields and their validation rules from the page. Level 2 attaches hooks to the same
submit pipeline. Pick the lowest level that covers what you need, and read on when it stops
covering it.

<a name="configuration-quickstart"></a>
## Configuration Quickstart

To see the whole configuration at once, here is a four-step checkout at `/checkout/:step?`:
pick a store, pick products, enter the buyer, review and pay. Every other page in this
documentation extends this example.

<a name="the-checkout-page"></a>
### The Checkout Page

```html
title = "Checkout"
url = "/checkout/:step?"
layout = "default"
==
<?
use Zimudec\Wizard\Support\Builder as WizardBuilder;

// The catalog is your data — a model in a real project. This page keeps it
// in plain functions so the example reads without a database.
function stores(): array
{
    return ['center' => 'Downtown', 'north' => 'Northside'];
}

function products(): array
{
    return ['widget' => 15, 'gadget' => 40];
}

// One owner for the shape of the cart: the step's extra validation and the
// auxiliary handler below both build it here. Returns null when empty.
function cart(array $quantities): array
{
    $items = [];
    $subtotal = 0;

    foreach ($quantities as $code => $quantity) {
        $quantity = (int) $quantity;

        if ($quantity < 1 || !isset($this->products()[$code])) {
            continue;
        }

        $items[$code] = ['quantity' => $quantity, 'price' => $this->products()[$code]];
        $subtotal += $quantity * $this->products()[$code];
    }

    return $items ? ['subtotal' => $subtotal, 'items' => $items] : null;
}

function onInit()
{
    $this->wizard->define(function (WizardBuilder $builder) {
        $builder
            ->finishUrl('/checkout/complete')

            ->step('branch', 'Pick your store')
            ->fields([
                ['name' => 'store', 'type' => 'select', 'label' => 'Store', 'options' => $this->stores()],
            ])
            ->rules(['store' => 'required'])
            ->extraValidation(function ($validator, $data) {
                if (!isset($this->stores()[$data['store'] ?? ''])) {
                    $validator->errors()->add('store', 'That store is not available');
                }
            })
            ->end()

            ->step('items', 'Pick your products')
            ->fields([
                ['name' => 'quantity_widget', 'label' => 'Widgets'],
                ['name' => 'quantity_gadget', 'label' => 'Gadgets'],
            ])
            ->rules([
                'quantity_widget' => 'nullable|integer|min:0',
                'quantity_gadget' => 'nullable|integer|min:0',
            ])
            ->extraValidation(function ($validator, $data) {
                $cart = $this->cart([
                    'widget' => $data['quantity_widget'] ?? 0,
                    'gadget' => $data['quantity_gadget'] ?? 0,
                ]);

                if ($cart === null) {
                    // A business rule with no field in the DOM reaches the
                    // form-level error container instead of being dropped.
                    $validator->errors()->add('bundles', 'Select at least one product');
                }

                // Returning an array persists derived data for the later steps.
                return $cart ? ['totals' => $cart] : [];
            })
            ->end()

            ->step('buyer', 'Buyer details')
            ->fields([
                ['name' => 'name', 'label' => 'Full name'],
                ['name' => 'email', 'label' => 'Email'],
            ])
            ->rules(['name' => 'required', 'email' => 'required|email'])
            ->end()

            ->step('review', 'Review and pay')
            ->fields([
                ['name' => 'payment_method', 'type' => 'select', 'label' => 'Payment method', 'options' => [
                    'card' => 'Card', 'transfer' => 'Transfer',
                ]],
                ['name' => 'terms', 'label' => 'I accept the terms'],
            ])
            ->rules(['payment_method' => 'required', 'terms' => 'required'])
            ->commit(function ($data, $previous) {
                Order::create([
                    // 'totals' was derived by the "items" step, so it arrives in
                    // the second argument. See The Two Data Arguments.
                    'total' => $previous['totals']['subtotal'],
                    'method' => $data['payment_method'],
                ]);
            })
            ->keepData()
            ->end();
    });
}

// Auxiliary AJAX handler of the "items" step: the cart shown next to the form.
function onCartData()
{
    $data = $this->wizard->data();

    if (empty($data['totals'])) {
        return $this->wizard->redirectTo('branch');
    }

    return ['cart' => $data['totals']];
}
?>
==
{% component 'wizard' %}
```

`define()` receives a `Zimudec\Wizard\Support\Builder`. Each `->step()` returns a step
builder, the calls between `->step()` and `->end()` configure that step, and `->end()`
returns to the wizard builder so you can declare the next step.

> [!NOTE]
> `$this->wizard` is the component instance, reached through the alias the page declares in
> `{% component 'wizard' %}`. A page that names the component differently must use that name.

<a name="what-ends-up-in-the-session"></a>
### What Ends Up in the Session

The checkout leaves this behind, one entry per step:

| Step | Submitted and persisted | Derived |
|---|---|---|
| `branch` | `store` | — |
| `items` | `quantity_widget`, `quantity_gadget` | `totals` |
| `buyer` | `name`, `email` | — |
| `review` | `payment_method`, `terms` | — |

Nothing else is stored. A field the step did not declare is discarded, and so is an uploaded
file — see [What Persists](session.md#what-persists).

<a name="level-0-properties-only"></a>
## Level 0: Properties Only

Level 0 configures the whole walk from the Inspector, with no code in the page. Add the
component, set `steps` to the step codes, set `titles` to their names, and the wizard renders
the indicator, the form and the buttons, and advances on its own:

```html
title = "Survey"
url = "/survey/:step?"
layout = "default"
==
{% component 'wizard' %}
```

With `steps = "step-1|step-2|step-3"` the wizard serves `/survey/step-1`, `/survey/step-2`
and `/survey/step-3`, in that order. With no step in the URL it redirects to the first one.

Add `fields` and each step renders one text input per name. Level 0 infers no validation
rules: a declared field is persisted and re-filled when the user comes back, and it is never
rejected. When you need a rule, move to level 1.

<a name="component-properties"></a>
## Component Properties

The eleven properties the Inspector exposes, with their defaults:

| Property | Type | Default | What it does |
|---|---|---|---|
| `steps` | string | `''` | Step codes, separated by `\|` or `,`. The order defines the flow |
| `titles` | string | `''` | Step titles in the same order. A step without a title shows its code |
| `fields` | string | `''` | Field names rendered as text inputs on **every** step |
| `finishUrl` | string | `/` | Where the browser lands when the last step completes |
| `ttl` | string | `''` | Inactivity timeout in minutes. Empty takes the global default, `0` disables it |
| `wipeOnBack` | checkbox | `true` | Clear the data of the later steps when the user goes back |
| `retainData` | checkbox | `false` | Keep that data, and make the completed steps clickable |
| `encrypted` | checkbox | `false` | Encrypt the data persisted in the session |
| `keepSteps` | string | `''` | Step codes whose data survives going back |
| `css` | checkbox | `true` | Load the built-in stylesheet |
| `js` | checkbox | `true` | Load the built-in script |

`steps` and `titles` are positional: the third title belongs to the third code. A step whose
title is missing, or equal to its code, renders without a heading — which is what you want
when a code is already a readable label.

<a name="the-list-syntax"></a>
### The List Syntax

`steps`, `titles`, `fields` and `keepSteps` accept the same notation: entries separated by a
pipe or a comma, each one trimmed, empty entries dropped. All of these declare the same
three steps:

```
step-1|step-2|step-3
step-1, step-2, step-3
step-1 | step-2 |
```

<a name="level-1-the-builder"></a>
## Level 1: The Builder

A page that calls `define()` configures the steps in code and overrides the properties
entirely — the component properties `steps`, `titles` and `fields` are then not read. The
wizard-wide settings declared in [Wizard-wide Settings](#wizard-wide-settings) still work as
a fallback, per setting.

The builder offers exactly one entry point:

| Call | What it does |
|---|---|
| `$this->wizard->define($closure)` | Declare the wizard. The closure receives the builder; return nothing |

<a name="level-2-hooks"></a>
## Level 2: Hooks

Level 2 is level 1 plus closures on the submit pipeline. Three of them are declared per step:

| Call | What it does |
|---|---|
| `->extraValidation($closure)` | Checks the rules cannot express. Add errors to block the advance; return an array to persist derived data |
| `->commit($closure)` | Side effects, exactly once per successful submit, before the advance |
| `->handler($closure)` | Optional. Return a `RedirectResponse` to choose the destination; return nothing for the next step |

The [validation documentation](validation.md#the-submit-pipeline) covers what each closure
receives and the order the pipeline runs them in.

<a name="wizard-wide-settings"></a>
## Wizard-wide Settings

These six settings apply to the whole wizard rather than to one step. Each has a builder
method and a component property, and each is a no-op when neither is declared.

| Builder method | Property | Default | What it does |
|---|---|---|---|
| `->finishUrl($url)` | `finishUrl` | `/` | Where the last step lands |
| `->ttl($minutes)` | `ttl` | global, 30 | Inactivity timeout. `0` disables it; omitted takes the global value |
| `->wipeOnBack($bool)` | `wipeOnBack` | `true` | Clear the data of the later steps when going back |
| `->retainData($bool)` | `retainData` | `false` | Keep that data, and make the completed steps clickable |
| `->encrypted($bool)` | `encrypted` | `false` | Encrypt the data persisted in the session |
| `->keepSteps($codes)` | `keepSteps` | `[]` | Step codes whose data survives going back |

`->keepSteps()` accepts an array of codes or the same `|`-separated string the property takes.
A code that is not a step of the wizard throws when the configuration is first resolved.

<a name="precedence"></a>
### Precedence

Precedence is **per setting, not wholesale**. A setting the page declares through the builder
wins. A setting the page leaves alone falls back to the component property. A setting neither
declares takes the default.

```php
$this->wizard->define(function (WizardBuilder $builder) {
    $builder
        ->ttl(10)              // declared: wins over the "ttl" property
        // 'encrypted' is not declared: the "encrypted" property applies
        ->step('only', 'Only step');
});
```

> [!NOTE]
> This is why a privacy setting such as `encrypted` is reachable from a page that uses
> `define()`. Earlier behaviour read the builder configuration in place of the properties
> whenever `define()` was called, which made four of the six settings silently unreachable on
> exactly the pages that needed them.

<a name="fields"></a>
## Fields

<a name="field-keys"></a>
### Field Keys

A field is an array. Only `name` is required.

| Key | Default | What it does |
|---|---|---|
| `name` | — | The input name. Also the key the validation rules use and the key the value is stored under |
| `type` | `'text'` | Which partial renders it. See [Field Types](#field-types) |
| `label` | `''` | The visible label |
| `options` | `[]` | The choices of a `select`, as `value => label` |
| `attributes` | `[]` | Extra HTML attributes, as `name => value` |

```php
->step('buyer', 'Buyer details')
->fields([
    ['name' => 'name', 'label' => 'Full name'],
    ['name' => 'email', 'label' => 'Email', 'attributes' => ['autocomplete' => 'email']],
    ['name' => 'store', 'type' => 'select', 'label' => 'Store', 'options' => $this->stores()],
])
```

Declare `autocomplete` on the fields that collect information about the user — see
[Accessibility](accessibility.md).

<a name="field-types"></a>
### Field Types

The dispatcher partial knows two types: `select` renders a `<select>`, and **anything else
renders a text input**. A declared `checkbox` or `date` type therefore renders as a text
input, without an error.

To add a type, override the dispatcher as well as the new partial — see
[View Overrides](customization.md#view-overrides).

<a name="configuration-errors"></a>
## Configuration Errors

An invalid configuration throws `Zimudec\Wizard\Support\ConfigurationException` on the first
request that resolves it, with a translated message. Every one of the six names the step or
the page at fault, so the message alone tells you what to change.

| Message key | Cause |
|---|---|
| `steps_empty` | Neither the `steps` property nor `define()` declared a step |
| `step_param_missing` | The page URL has no `:step?` |
| `step_code_empty` | A step was declared without a code |
| `field_name_empty` | A field was declared without a name |
| `step_not_found` | A call named a step code the wizard does not declare |
| `keep_step_unknown` | `keepSteps` names a code that is not a step of the wizard |

Next, continue to [Validation](validation.md).
