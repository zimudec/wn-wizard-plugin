# Upgrade Guide

- [Introduction](#introduction)
- [The 1.x Pattern](#the-1x-pattern)
- [The Same Wizard in 2.0](#the-same-wizard-in-20)
- [What Changes](#what-changes)
- [Behavior Changes](#behavior-changes)

<a name="introduction"></a>
## Introduction

2.0 is a breaking release. The configuration, the handlers, the session key and the views all
change. The `1.x` branch stays frozen for critical fixes only.

This guide covers the public surface of the plugin. The shape of your own data — carts,
catalogs, lookups — is yours in both versions, so nothing about it appears here. The
changelog lists the rest: [CHANGELOG.md](../CHANGELOG.md).

<a name="the-1x-pattern"></a>
## The 1.x Pattern

A `steps` array declared in `onInit`, one page handler per step, and the form written by hand
in the page:

```html
title = "Wizard Example"
url = "/wizard-example/:step?"
==
<?
function onInit()
{
    $this->wizard->steps = [
        ['step' => 'step1', 'name' => 'Step 1', 'forms' => [
            'onStep1' => [
                'validation' => ['field1' => 'required', 'field2' => 'required'],
                'extra_validation' => function ($validator, $fields, $prevValidationsData) {
                    if ($fields['field1'] != 'hello') {
                        $validator->errors()->add('field1', 'The value of this field must be "hello"');
                    }
                    return ['user' => ['id' => 1, 'names' => 'User']];
                },
            ],
        ]],
        ['step' => 'step2', 'name' => 'Step 2', 'validatePrevSteps' => true],
    ];
}

function onStep1()
{
    $data = $this->wizard->formsValidate();

    return redirect($data['stepNext']);
}
?>
==
{% if wizard.stepCurrent == 'step1' %}
    <form data-request="onStep1" data-request-validate>
        {% partial '@input_text.htm' label="Field 1 *" name="field1" %}
        {% partial '@nav_buttons.htm' %}
    </form>
{% endif %}
```

<a name="the-same-wizard-in-20"></a>
## The Same Wizard in 2.0

A builder declared in `onInit`, one generic handler for every step, and no form markup in the
page:

```html
title = "Wizard Example"
url = "/wizard-example/:step?"
==
<?
use Zimudec\Wizard\Support\Builder as WizardBuilder;

function onInit()
{
    $this->wizard->define(function (WizardBuilder $builder) {
        $builder
            ->step('step1', 'Step 1')
            ->fields([
                ['name' => 'field1', 'label' => 'Field 1 *'],
                ['name' => 'field2', 'label' => 'Field 2 *'],
            ])
            ->rules(['field1' => 'required', 'field2' => 'required'])
            ->extraValidation(function ($validator, $data) {
                if ($data['field1'] != 'hello') {
                    $validator->errors()->add('field1', 'The value of this field must be "hello"');
                }
                return ['user' => ['id' => 1, 'names' => 'User']];
            })
            ->end()

            ->step('step2', 'Step 2')
            ->revalidate()
            ->end();
    });
}
?>
==
{% component 'wizard' %}
```

The `extra_validation` closure takes one argument fewer. In 1.x it received the step's own
fields and, separately, the accumulated data of the previous steps. In 2.0 its single data
argument is the union of the two: the accumulated data of the previous steps merged with the
whitelisted input of the current step.

<a name="what-changes"></a>
## What Changes

The public surface, and nothing else.

| 1.x | 2.0 |
|---|---|
| `$this->wizard->steps = [...]`, an array declared in `onInit` | `$this->wizard->define(fn (Builder $b) => …)` or the component properties |
| `['step' => 'code', 'name' => 'Title']` | `->step('code', 'Title')`, and the page URL declares `/:step?` |
| `['forms' => ['onStep1' => […]]]` plus a page handler per step | One generic handler for every step; the step in the URL selects the configuration |
| `formsValidate()` and `redirect($data['stepNext'])` | The pipeline validates and advances on its own; `redirectTo()` and `jumpTo()` when you choose the destination |
| `validation`, `validation_messages` | `->rules([...])`, `->messages([...])` |
| `validatePrevSteps` | `->revalidateOnSubmit()` or `->revalidate()` |
| `keep_sesion` (the typo is in the 1.x API) | `->keepData()` |
| `lang/<lang>/zimudec/wizard/validations.php` | Application `lang/` overrides — see [Customize the Texts](customization.md#customize-the-texts) |
| The page writes the form and branches on `wizard.stepCurrent` | The component renders the form; override `partials/wizard/*` in the theme |
| `@input_text.htm`, `@input_select.htm`, `@nav_buttons.htm` | Declared fields with `->fields()`, with `field_text.htm` and `field_select.htm` as theme overrides |
| `wizard.fields` (the raw session) | `wizard.data` |
| `wizard.prevValidationsData` | `wizard.data`, with the derived data merged into it |
| `wizard.stepNext`, `wizard.stepPrev` | `wizard.urls.next`, `wizard.urls.prev` |
| `wizard.stepCurrent` (the step code) | The entry with `state == 'current'` in `wizard.steps` |
| `wizard.stepPos` | `wizard.stepNumber` |
| `wizard_steps-<pageFileName>`, a flat array | `zimudec.wizard.<page>.<alias>`, with `data` (per step) and `meta` separated |
| `exit()`, `->send()`, `dump()` | Framework-native `RedirectResponse` and `ValidationException` |
| jQuery `assets/js/wizard.js` with Bootstrap 4 classes | Snowboard, and a stylesheet inside a CSS cascade layer |
| A gated AJAX handler, via `cms.ajax.beforeRunHandler` | A guard your handler declares — see [Guarding a Handler](programmatic-control.md#guarding-a-handler) |

A step that needs its own markup declares `->view('my/partial')`; the conventions such a
partial must follow are in
[Write a Custom Step Partial](customization.md#write-a-custom-step-partial).

<a name="behavior-changes"></a>
## Behavior Changes

Differences of semantics rather than of names. Each one affects you only under the stated
condition.

- **Going back clears whole steps.** 1.x cleared the keys declared as validation fields, so
  data a step derived survived going back. 2.0 clears the step itself, so anything a later
  step derived is gone with it. This affects you if a step computes data that a step the user
  can return from depends on. Name the steps whose data must survive with
  `->keepSteps(['beneficiaries'])`; use `retainData` when you want every later step to
  survive and the completed steps to become clickable.
- **Persistence is declared, not captured.** Only the rule keys and the declared fields of a
  step are stored. 1.x serialized what the request carried, so a field you validated but did
  not declare was kept; now it is discarded, along with uploaded files.
- **The error bag is not a response channel.** Anything added to the bag makes the step fail,
  so passing a control value through an error key is now a blocked step. Return it as derived
  data from `extraValidation` instead.
- **A failed revalidation lands on the first incomplete step**, not on the first step. The
  data of the earlier steps is kept.
- **Revalidation is off unless declared.** 1.x ran `validatePrevSteps` when the step asked for
  it, and so does 2.0 — but neither `revalidateOnSubmit()` nor `revalidate()` runs at all
  unless the step declares it, and a step migrated without that line silently stops
  revalidating.
- **Step pages answer `Cache-Control: no-store`**, so the values a user typed are not left in
  the browser cache.
- **New defaults in the stored data:** a 30-minute inactivity timeout, whitelisted persistence
  and optional encryption. None is a break, and all three change what your session holds.

Next, continue to [Configuration](configuration.md).
