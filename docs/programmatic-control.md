# Programmatic Control

- [Introduction](#introduction)
- [Auxiliary Handlers](#auxiliary-handlers)
- [Guarding a Handler](#guarding-a-handler)
    - [The Render Guard](#the-render-guard)
- [An Auxiliary Handler that Persists What it Finds](#an-auxiliary-handler-that-persists-what-it-finds)
- [Deep Link with Prefill](#deep-link-with-prefill)
- [A Conditional Skip Inside a Step Handler](#a-conditional-skip-inside-a-step-handler)
- [Jumping Over Steps that Need Data](#jumping-over-steps-that-need-data)

<a name="introduction"></a>
## Introduction

The wizard owns its own submit: validation, storage, the redirect to the next step, and the
gating of a step the user has not reached. What it does not own is the AJAX handler your page
declares for a lookup, a cart panel or an autosave.

This page covers writing those handlers against the wizard's state, and the guards they need.
The calls themselves are documented in [Session API](session.md#session-api) and
[Navigation API](navigation.md#navigation-api); the examples extend the checkout declared in
the [Configuration Quickstart](configuration.md#configuration-quickstart) and show only the
part they add.

<a name="auxiliary-handlers"></a>
## Auxiliary Handlers

An auxiliary handler is an ordinary page handler. Winter runs it directly, and the wizard does
not intercept it:

```php
// Auxiliary handler of the "items" step: the cart shown next to the form.
function onCartData()
{
    $data = $this->wizard->data();

    if (empty($data['totals'])) {
        return $this->wizard->redirectTo('branch');
    }

    return ['cart' => $data['totals']];
}
```

Returning an array hands it to Snowboard as the handler response; returning a `RedirectResponse`
becomes `X_WINTER_REDIRECT`.

> [!NOTE]
> The 1.x component gated every AJAX handler through the `cms.ajax.beforeRunHandler` event, so
> an unguarded handler could not be called out of order. In 2.0 the gating is explicit: a
> handler is a page handler, and it declares the guard it needs.

<a name="guarding-a-handler"></a>
## Guarding a Handler

`requireData()` guards a handler and returns its data in one call. It returns the merged data when
the step is reachable and holds the named keys. If not, it returns a redirect to the furthest step
the user has reached:

```php
function onCartData()
{
    $data = $this->wizard->requireData('items', ['totals']);

    if ($this->wizard->needsRedirect($data)) {
        return $data;
    }

    return ['cart' => $data['totals']];
}
```

<a name="the-render-guard"></a>
### The Render Guard

A guard on the page itself needs `onEnd()`, because `onInit()` cannot redirect — the CMS
discards its return value. `onEnd()` runs after the components and may return a response:

```php
// GET /checkout/review — the review page needs a cart with data.
function onEnd()
{
    if ($this->param('step') === 'review' && empty($this->wizard->data()['totals'])) {
        return $this->wizard->redirectTo('branch');
    }
}
```

The render guard is the one that matters most: without it, a guardless step renders a form
whose submit then fails, and the user fills in a page that was never going to accept it.

<a name="an-auxiliary-handler-that-persists-what-it-finds"></a>
## An Auxiliary Handler That Persists What it Finds

A lookup result is not a form field. Persist it through the session API so a later step can
read it:

```php
// AJAX handler, called from the page while the user is on /checkout/buyer.
function onLookupUser()
{
    $data = $this->wizard->data();
    $user = User::where('email', $data['email'] ?? '')->first();

    // Written to the "buyer" step, so the review step reads it with the rest
    // of that step's data.
    $this->wizard->saveData(['user' => ['id' => $user?->id]], 'buyer');

    return ['exists' => $user !== null];
}
```

`saveData()` validates nothing, which is the point: these keys are not form fields and a
crafted request must not be able to reach them through the pipeline's whitelist.

<a name="deep-link-with-prefill"></a>
## Deep Link With Prefill

URL parameters prefill data and unlock the requested step:

```php
// Entry URL — GET /checkout?store=center
function onInit()
{
    if (request()->ajax() || !get('store')) {
        return;
    }

    // Indexed by step code. Unlocking a step does not run its rules, so the
    // prefill must carry whatever the later steps read.
    $this->wizard->jumpTo('items', ['branch' => ['store' => get('store')]]);
}
```

Three things make this work, and they are worth separating:

- The `jumpTo()` runs for its **effects**: it persists the prefill and unlocks the requested
  step.
- Its return value is **discarded**, because the CMS ignores what `onInit()` returns. The user
  still lands on the URL they requested, and the wizard's own redirect from the render carries
  the query string on.
- To **move** the user to the target step instead, that needs a real redirect: write the state
  in `onInit()` and return the same `jumpTo()` from `onEnd()`.

<a name="a-conditional-skip-inside-a-step-handler"></a>
## A Conditional Skip Inside a Step Handler

A step whose purpose is already covered can skip the rest. Here the `branch` handler sends the
user straight to the review when the URL asked for an express checkout:

```php
// POST /checkout/branch?express=1&product=gadget
->step('branch', 'Pick your store')
->fields([['name' => 'store', 'type' => 'select', 'label' => 'Store', 'options' => $this->stores()]])
->rules(['store' => 'required'])
->handler(function ($data) {
    $product = (string) get('product');
    $cart = $this->cart([$product => 1]);

    if (get('express') !== '1' || $cart === null) {
        return;                       // the wizard advances on its own
    }

    // The prefill the skipped "items" step would have produced: the raw
    // quantity and the derived cart the review guard reads.
    return $this->wizard->jumpTo('review', [
        'items' => ['quantity_' . $product => 1, 'totals' => $cart],
    ], ['express' => '1']);
})
->end()
```

| Argument | What it does |
|---|---|
| `'review'` | The target step. The progress pointer moves to it and the user is redirected there |
| `['items' => …]` | The prefill for the skipped step, indexed by step code. Include the **derived data** the later steps read: the review render guard checks `totals`, so a skip without it bounces the user to step 1. Free-form keys work too (`['buyer' => ['express' => true]]` is a marker, not a form field) |
| `['express' => '1']` | The query string appended to the redirect URL |

A handler that returns a redirect takes over the navigation: the automatic next-step redirect
and the finish cleanup are both skipped, and the wizard state stays alive. That is what a
payment flow needs while the gateway calls back.

The submitted `branch` data is still persisted — the pipeline stores it either way.

<a name="jumping-over-steps-that-need-data"></a>
## Jumping Over Steps That Need Data

`jumpTo()` unlocks but does not validate. The rules of the skipped steps never run during the
jump, and only the target step's rules run on its own submit. Before jumping over a step that
collects required data, choose one:

- **Prefill** what it needs, including the **derived data** the later steps read. The handler
  above computes the cart at jump time for exactly that reason.
- **Tolerate the absence** in the later code (`$data['buyer'] ?? []`), and only when no guard
  depends on it — a guard that checks for the missing data bounces the target step on arrival.

Declaring `->revalidateOnSubmit()` on a flow that skips steps is the recommended safety net:
the user lands on the first step that no longer passes, with the earlier data intact. See
[The Bounce](validation.md#the-bounce).

> [!NOTE]
> `->revalidate()` adds the entry gate on top of that, and a skipped step whose rules are
> `required` and whose data is empty will stop the visit at the door. Prefer
> `revalidateOnSubmit()` unless you want those visits blocked as well.

Next, continue to [Customization](customization.md).
