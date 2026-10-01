# Validation

- [Introduction](#introduction)
- [The Submit Pipeline](#the-submit-pipeline)
- [Rules](#rules)
    - [What the Rules See](#what-the-rules-see)
    - [Custom Messages](#custom-messages)
- [Extra Validation](#extra-validation)
- [The Two Data Arguments](#the-two-data-arguments)
    - [The Commit Hook](#the-commit-hook)
    - [The Handler](#the-handler)
- [Revalidating Previous Steps](#revalidating-previous-steps)
    - [On Submit Only](#on-submit-only)
    - [Also Gating the Entry](#also-gating-the-entry)
    - [The Bounce](#the-bounce)
- [Cycle Events](#cycle-events)
- [Step Methods](#step-methods)

<a name="introduction"></a>
## Introduction

Every step of the wizard is validated by the same pipeline, and the pipeline is what advances
the flow. Your page declares the rules and, where rules cannot express the check, closures
that run at a defined point of the submit. The wizard owns the transitions: it validates,
stores, redirects and gates, and your code never terminates a request.

All validation runs through Laravel's validator, so every rule, message and language file of
the framework is available. The plugin adds no rule of its own.

<a name="the-submit-pipeline"></a>
## The Submit Pipeline

One submit handler serves every step — `wizard::onNext` — and the rules it applies are those
of the step in the URL, never the handler name the client asked for. The phases always run in
this order:

1. **Whitelisting.** The request is reduced to the keys the step declared. See
   [What Persists](session.md#what-persists).
2. **Base rules.** `rules` run over the accumulated data of the previous steps merged with the
   whitelisted input. A failure reports per-field errors and nothing is stored.
3. **Revalidation of the previous steps.** Only when the step declares it, and only when the
   base rules passed. See [Revalidating Previous Steps](#revalidating-previous-steps).
4. **`wizard.beforeValidate`** fires. See [Cycle Events](#cycle-events).
5. **`extraValidation`.** Your closure, with the whole accumulated picture in its second
   argument. Add errors to block; return an array to persist derived data.
6. **`commit`.** Your closure, once per successful submit, for the side effect that must not
   be repeated. Throw a `ValidationException` to block.
7. **`handler`.** Your closure, if declared. Return a `RedirectResponse` to choose the
   destination; return nothing for the automatic next step.
8. **`wizard.afterCommit`** fires.
9. **Persistence.** The whitelisted input and the derived data are stored under the step's
   code.
10. **`wizard.stepCompleted`** fires.
11. **Advance or finish.** The browser is redirected to the next step, or to `finishUrl` after
    the last one.

Because the current step's field errors are reported before the revalidation runs, a user with
a wrong email address sees the email error rather than a bounce to a step they already
completed.

<a name="rules"></a>
## Rules

`->rules()` takes a standard Laravel rules array, keyed by the field name:

```php
->step('buyer', 'Buyer details')
->fields([
    ['name' => 'name', 'label' => 'Full name'],
    ['name' => 'email', 'label' => 'Email'],
])
->rules([
    'name' => 'required|min:2',
    'email' => 'required|email',
])
```

The keys of the rules are also the persistence whitelist. A field declared in `fields` without
a rule is stored and never validated; a rule without a declared field is stored and renders no
input.

<a name="what-the-rules-see"></a>
### What the Rules See

The base rules run over the accumulated data of the previous steps **merged with** the
whitelisted input of the current one. A current-step rule that names a key an earlier step
stored therefore validates the stored value, which is what a cross-step constraint needs:

```php
->step('review', 'Review and pay')
->rules([
    // "store" was stored by the "branch" step.
    'store' => 'required|in:center,north',
])
```

The validator only ever sees whitelisted data, because whitelisting runs first. A key that no
rule and no declared field reaches is discarded before validation, so it cannot fail a rule
either.

<a name="custom-messages"></a>
### Custom Messages

`->messages()` takes `field.rule` pairs and overrides the framework message for that step:

```php
->rules(['email' => 'required|email'])
->messages([
    'email.required' => 'We need your email address to send the receipt',
    'email.email'    => 'That does not look like an email address',
])
```

Step messages take precedence over the application language files. For messages that apply to
every wizard in the application, override the language file instead — see
[Customize the Texts](customization.md#customize-the-texts).

> [!NOTE]
> A nested key appears in the message as its own dotted path: a failure on `items.0.sku`
> reads "The items.0.sku field is required." Override the `attributes` array in
> `lang/<code>/validation.php` to give those keys a readable name.

<a name="extra-validation"></a>
## Extra Validation

`->extraValidation()` takes a closure that receives the validator and the data. Use it for the
checks a rule cannot express: a store that exists, quantities that add up, a domain you do not
accept.

```php
->extraValidation(function ($validator, $data) {
    if (!isset($this->stores()[$data['store'] ?? ''])) {
        $validator->errors()->add('store', 'That store is not available');
    }
})
```

Adding an error for a field that is **not** in the DOM is not a mistake and not a warning. The
wizard's script collects the messages with no matching input into the form-level error
container at the top of the form and focuses it — see
[Form-level Errors](customization.md#form-level-errors).

Return an array to persist **derived data** for the later steps. The values merge into the
step's own stored data, so a later step reads them from the accumulated picture:

```php
->extraValidation(function ($validator, $data) {
    $cart = $this->cart([...]);

    if ($cart === null) {
        $validator->errors()->add('bundles', 'Select at least one product');
    }

    return $cart ? ['totals' => $cart] : [];
})
```

<a name="the-two-data-arguments"></a>
## The Two Data Arguments

`commit()` and `handler()` receive **two** data arguments, and the split is the same for
both:

| Argument | What it holds |
|---|---|
| First | **This step**: its whitelisted input merged with the derived data its own `extraValidation` returned |
| Second | **The previous steps only**: the accumulated data of every step before this one |

`extraValidation()` is different: its single data argument is the **whole** picture, the
accumulated data of the previous steps merged with this step's input. There is no second
argument because there is nothing to disambiguate.

> [!WARNING]
> Reading this step's data from the second argument fails silently. You get the value an
> earlier step stored under the same name, and nothing reports an error. In the checkout
> example, the `review` step reads `totals` from the second argument because the `items` step
> derived it — and an order created from the first argument would carry no total at all.

<a name="the-commit-hook"></a>
### The Commit Hook

`commit()` runs exactly once per successful submit, before the advance. It is the place for
the side effect that must not be repeated: creating a record, queueing an email, calling a
payment API. Throwing a `ValidationException` blocks the advance and reports your error.

```php
->commit(function ($data, $previous) {
    Order::create([
        'total'  => $previous['totals']['subtotal'],
        'method' => $data['payment_method'],
    ]);
})
```

> [!NOTE]
> The hook runs once per submit, not once per session. A double submission is cancelled in the
> browser, and a client-side guard cannot rule out a concurrent duplicate — carry your own
> idempotency key in the side effect. See [Idempotency](standards.md#idempotency).

<a name="the-handler"></a>
### The Handler

`handler()` is optional and decides the destination. Return nothing and the wizard advances to
the next step on its own. Return a `RedirectResponse` — from `jumpTo()` or `redirectTo()` — and
the wizard skips both the automatic redirect and the finish cleanup, and stays alive.

That is what a payment flow needs: the state must survive while the gateway calls back. See
[A Conditional Skip](programmatic-control.md#a-conditional-skip-inside-a-step-handler).

```php
->handler(function ($data, $previous) {
    if ($this->wizard->stepCurrent() < 2) {
        return $this->wizard->redirectTo('branch');
    }
})
```

<a name="revalidating-previous-steps"></a>
## Revalidating Previous Steps

Data in the session can go stale: a branch closes, a discount expires, a campaign changes. A
step may revalidate every step before it with that step's own rules, and bounce the user to the
first step that no longer passes.

> [!WARNING]
> Revalidation is **off by default**. Neither `revalidateOnSubmit()` nor `revalidate()` runs
> unless the step declares it. A wizard whose steps declare neither never revalidates
> anything.

<a name="on-submit-only"></a>
### On Submit Only

`->revalidateOnSubmit()` revalidates the previous steps when the user submits. Visits do not
pay the cost, and the submit catches broken previous data through the bounce.

This is the recommended choice: the same integrity at a lower cost.

<a name="also-gating-the-entry"></a>
### Also Gating the Entry

`->revalidate()` runs the same revalidation, and additionally gates the **entry**: entering the
step revalidates the previous steps before the form is shown, so the user never fills a form
that cannot be advanced.

Use it when the step can be reached with broken previous data — a deep link, a campaign jump,
a session that outlived a stock change. Because the entry gate blocks the visit, a skipped step
whose rules are `required` and whose data is empty will stop the user at the door; prefer
`revalidateOnSubmit()` on flows that skip steps.

<a name="the-bounce"></a>
### The Bounce

A failed revalidation behaves like a "back" to the first step that no longer passes:

- The data of the failing step and of every later step is cleared.
- The data of the earlier steps is kept.
- The user lands on the failing step to re-fill it.

If the **first** step fails, the whole state is flushed and the flow restarts, exactly as an
expired session does.

Each previous step is revalidated with its own rules **and** its own `extraValidation`, which
receives the accumulated data of the steps before it — the same context it had the first time.
A cross-step closure therefore behaves identically on first validation and on revalidation. A
step that passes refreshes its derived data in the session.

> [!NOTE]
> The bounce drops the query string. Re-running a deep-link campaign with the same invalid
> prefill would otherwise loop the user between the landing and the revalidation.

<a name="cycle-events"></a>
## Cycle Events

Three events let other plugins observe a submit. Each receives the component instance, the
`StepConfig` of the step, and the data described below.

```php
Event::listen('wizard.beforeValidate', function ($wizard, $step, $data) { /* ... */ });
Event::listen('wizard.afterCommit', function ($wizard, $step, $data) { /* ... */ });
Event::listen('wizard.stepCompleted', function ($wizard, $step, $data) { /* ... */ });
```

| Event | Fires | The data it carries |
|---|---|---|
| `wizard.beforeValidate` | after the revalidation, before `extraValidation` | the accumulated data of the previous steps merged with the whitelisted input |
| `wizard.afterCommit` | after `commit()` and `handler()`, **before** the step is persisted | the accumulated state **without** the step being submitted |
| `wizard.stepCompleted` | **after** the step is persisted | the accumulated state **including** the step just completed |

The last two are siblings with different data contracts, and the names invite the wrong
expectation. If a listener needs the data the user just submitted, use `stepCompleted`.
`afterCommit` exists to react to the hooks having run, not to the data being stored.

Throwing a `ValidationException` in `wizard.beforeValidate` is the supported way to block the
advance from another plugin. A throw in the other two is not a blocking mechanism: in
`wizard.afterCommit` it aborts the request before the step is stored, and in
`wizard.stepCompleted` it aborts it after the step is stored and the progress pointer moved.

<a name="step-methods"></a>
## Step Methods

Everything `->step($code, $title)` returns. The wizard-wide settings live on the builder
itself — see [Wizard-wide Settings](configuration.md#wizard-wide-settings).

| Method | What it does |
|---|---|
| `->fields($fields)` | The step's fields. See [Field Keys](configuration.md#field-keys) |
| `->rules($rules)` | Laravel validation rules. Their keys are the persistence whitelist |
| `->messages($messages)` | Messages for this step, `field.rule` syntax |
| `->extraValidation($closure)` | Manual checks and derived data |
| `->commit($closure)` | Side effects, once per successful submit |
| `->handler($closure)` | Optional destination picker |
| `->view($partial)` | Render the step with your own partial instead of the default shell |
| `->revalidate($bool = true)` | Revalidate the previous steps, and gate the entry |
| `->revalidateOnSubmit()` | Revalidate the previous steps on submit only |
| `->keepData($bool = true)` | Keep the wizard state after the last step completes |
| `->end()` | Register the step and return to the wizard builder |

Next, continue to [Navigation](navigation.md).
