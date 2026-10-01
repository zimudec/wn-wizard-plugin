# Session

- [Introduction](#introduction)
- [What Persists](#what-persists)
    - [The Two Kinds of Write](#the-two-kinds-of-write)
    - [The Whitelist](#the-whitelist)
    - [Nested Data and Wildcard Rules](#nested-data-and-wildcard-rules)
- [The Store Shape](#the-store-shape)
- [The Inactivity Timeout](#the-inactivity-timeout)
- [Going Back](#going-back)
    - [Wipe on Back](#wipe-on-back)
    - [Keeping One Step Without Keeping the Rest](#keeping-one-step-without-keeping-the-rest)
    - [Retaining Everything](#retaining-everything)
- [Cleared on Finish](#cleared-on-finish)
- [Encryption](#encryption)
- [Session API](#session-api)
    - [Choosing a Read](#choosing-a-read)

<a name="introduction"></a>
## Introduction

The wizard keeps its state in one session key, namespaced by the page that declares the
component and by the component alias, so two wizards never share state. A wizard belongs to
the page that declares it. Inside that key, the data the user entered is kept apart from the
wizard's own metadata, and only the data is ever handed to a view.

Three decisions shape what that state contains: which keys are stored, how long it survives,
and what happens to it when the user goes back. This page covers all three. For what the
wizard promises about the data it handles, see [Privacy](privacy.md).

<a name="what-persists"></a>
## What Persists

A step never persists the request. It persists the keys it declared — the keys of its
validation rules and the names of its fields — and discards everything else, including
uploaded files.

<a name="the-two-kinds-of-write"></a>
### The Two Kinds of Write

| Kind | Written by | Validated | Whitelisted |
|---|---|---|---|
| Form submit | The submit pipeline | Yes, by the step's rules | Yes |
| Session API | `saveData()`, `jumpTo()` | No | No |

A **form submit** stores what the step declared. A field declared without a rule is stored and
never validated; a rule without a declared field is stored and renders no input.

A **session API write** stores any key you pass — a flag, an ID, a lookup result — because it
exists so a handler can persist what a later step needs. Those keys are not form fields and
nothing validates them, which is also why a crafted request cannot reach them.

<a name="the-whitelist"></a>
### The Whitelist

The whitelist widens to what you declared, never to what arrived. Two details of that rule
matter when a step handles nested data.

A declared key covers everything beneath it, which is what keeps a rule on a parent from
losing its contents. That widening stops as soon as a rule is declared **inside** the parent:
from then on, the rules below define what is kept, and the siblings no rule names are
discarded.

Whitelisting runs before validation, so the validator never sees a key the whitelist dropped,
and a key it dropped can never fail a rule either.

<a name="nested-data-and-wildcard-rules"></a>
### Nested Data and Wildcard Rules

Laravel's wildcard notation works, and a nested submission is stored with its nesting intact.
Declare the rule with the wildcard and the field with its array name:

```php
->step('items', 'Your items')
->fields([
    ['name' => 'items[0][sku]', 'label' => 'SKU'],
    ['name' => 'items[0][quantity]', 'label' => 'Quantity'],
])
->rules([
    'items.*.sku' => 'required|string',
    'items.*.quantity' => 'required|integer|min:1',
])
->end()
```

A POST of `items[0][sku]=KB-1&items[0][quantity]=2` is stored as
`['items' => [['sku' => 'KB-1', 'quantity' => '2']]]`, and comes back in that shape in
`commit()` and through the session API.

With a wildcard present, the whitelist is built from the concrete keys the validator itself
expands, so it cannot drift from the validation. The four cases:

| Rules declared | What a POST of `items[0][sku]` and `items[0][quantity]` stores |
|---|---|
| `items.*.quantity` | `items.0.quantity` only. `items.0.sku` is dropped: no rule names it |
| `items` and `items.*.quantity` | `items.0.quantity` only. The parent rule does **not** widen the whitelist, because a rule is declared inside it |
| `items => array` | The whole `items` subtree, because the parent rule is the only thing declared |
| `items.*.quantity`, no rows posted | Nothing from that group, and no error. A form where the user added no rows sends nothing for it, and the step advances |

A container that arrived empty is stored as an empty array rather than dropped, so reading it
back yields `[]` and not `null`.

> [!NOTE]
> Rows the user adds in the browser need a partial of your own — a step renders the fields it
> declared, and an added row has no input to render into. See
> [Write a Custom Step Partial](customization.md#write-a-custom-step-partial) for the
> conventions, including the input `id` that ties each field to its error key.

<a name="the-store-shape"></a>
## The Store Shape

The state lives under a single key built from the page and the component alias,
`zimudec.wizard.<page>.<alias>`, holding the data of each step plus the wizard's own metadata:

```php
[
    'data' => [
        'branch' => ['store' => 'center'],
        'items'  => ['quantity_widget' => '2', 'totals' => ['subtotal' => 30]],
    ],
    'meta' => ['stepCurrent' => 1, 'lastActivity' => 1767225600],
]
```

The two halves are separate so a form field that happens to be called `stepCurrent` cannot
corrupt the wizard's own state. Only `data` reaches the templates; the metadata never does.
The entries of `data` are ordered by the configured order of the steps, not by the order the
data was written, so the wipe boundaries and the merge order are the same on every request.

<a name="the-inactivity-timeout"></a>
## The Inactivity Timeout

The wizard expires after a period of inactivity, measured server-side and refreshed on every
render and every submit. The default is 30 minutes, set globally in
[Global Defaults](installation.md#global-defaults) and overridable per wizard with the `ttl`
property or `->ttl()`.

When the state expires, the next interaction flushes it and restarts the flow at the first
step. A submit that arrives after the timeout is not applied to the stale state: it restarts
the wizard instead.

`0` disables the timeout. Leaving it undeclared takes the global value — it does not disable
it.

<a name="going-back"></a>
## Going Back

Going back is a deletion, not a navigation. When the user returns to a step the wizard clears
the data of every step after it, which is the privacy default.

<a name="wipe-on-back"></a>
### Wipe on Back

The boundary is positional, resolved in the configured order of the steps. Returning to step
`items` keeps `branch` and `items`, and removes everything from `buyer` onwards.

The wipe is also why the completed steps in the step indicator are not clickable: an editable
stepper invites the user to revisit a step, and revisiting a step is exactly what clears the
later data. See [The Step Indicator](navigation.md#the-step-indicator).

<a name="keeping-one-step-without-keeping-the-rest"></a>
### Keeping One Step Without Keeping the Rest

`keepSteps` names the steps whose data survives the wipe. Every other later step is still
cleared, and the completed steps stay not clickable. It goes on the step that derives the data
a later step reads back:

```php
->step('beneficiaries', 'Beneficiaries')
// The rows this step writes are read back by the cart step, so they survive
// going back. The personal data of any later step is still cleared.
->keepSteps(['beneficiaries'])
->end()
```

This is the narrow case. A flow whose `items` step re-reads the rows a `beneficiaries` step
wrote needs those rows; it does not need the personal data of the step after it. The two
settings change different things:

| Setting | Later data | Completed steps clickable |
|---|---|---|
| `wipeOnBack` (default) | Cleared | No |
| `wipeOnBack(false)` | Kept | No |
| `retainData` | Kept | Yes |
| `keepSteps(['a'])` | Everything after the target is cleared except `a` | No |

A code in `keepSteps` that is not a step of the wizard throws when the configuration is first
resolved, before any step is served.

> [!WARNING]
> Derived data does not survive a back navigation unless you arrange for it to. Wipe-on-back
> clears the step that computed it along with the step itself, **and nothing warns you**: the
> page still renders, and the next submit finds less data than it expected. Declare
> `keepSteps` on the step that derives the data a later step reads.

The revalidation bounce ignores `keepSteps`. It wipes because the data is invalid, and keeping
the step that failed would defeat the correction that triggered the bounce.

<a name="retaining-everything"></a>
### Retaining Everything

`retainData` keeps the data of every later step and makes the completed steps clickable. It is
the opposite trade, and it also defers the deletion on finish — see
[Cleared on Finish](#cleared-on-finish).

`keepSteps` has no effect while `retainData` is on, because `retainData` already keeps every
later step.

<a name="cleared-on-finish"></a>
## Cleared on Finish

Submitting the last step normally removes the whole state. The removal happens at the end of
the request, so the data is available for the duration of that request and a confirmation page
rendered by the redirect target no longer finds it.

Two declarations defer the deletion until the next restart of the flow:

| Declaration | Effect |
|---|---|
| `->keepData()` on the last step | The state survives, for a confirmation page that needs it |
| `retainData` on the wizard | The same, because the wizard keeps data on back navigation and is expected to keep it on finish too |

A state marked this way is flushed on the next interaction with the wizard, so it is retained
for one flow, not indefinitely.

<a name="encryption"></a>
## Encryption

`encrypted` encrypts every persisted value with the application key. It is opt-in, and without
it the wizard uses the CMS's ordinary session storage.

Two properties of the implementation are worth knowing:

- Reading tolerates data written before the encryption was enabled, and data written with a
  previous `APP_KEY`. An undecryptable value is returned as it is stored: the form shows
  garbage rather than the request failing, and the user's next submit overwrites it.
- Values are serialized on write and deserialized with `allowed_classes => false` on read.
  Store arrays and scalars. An object comes back incomplete once encryption is on.

<a name="session-api"></a>
## Session API

The page's own AJAX handlers read and write the wizard's state through the component. Every
call goes through the same namespacing and encryption as the submit pipeline, so a handler
cannot reach state the pipeline would not, and cannot store outside the wizard's own key.

| Call | What it does |
|---|---|
| `$this->wizard->data()` | The data of every step merged, decrypted |
| `$this->wizard->stepsData()` | The data of every step, keyed by step code, nothing merged |
| `$this->wizard->stepData($stepCode = null)` | The data of one step. Omit the code for the current step |
| `$this->wizard->meta()` | The metadata: progress pointer, last activity, finished flag |
| `$this->wizard->stepCurrent()` | The index of the last validated step |
| `$this->wizard->saveData($data, $stepCode = null)` | Merge values into a step's data and refresh the inactivity timer |
| `$this->wizard->forgetData($keys, $stepCode = null)` | Remove named keys. Omit the code to remove them from every step |
| `$this->wizard->flush()` | Remove this wizard's state, and only this wizard's |

`stepData()`, `saveData()` and `forgetData()` throw a translated `ConfigurationException` naming
the code when it is not a step of the wizard.

`saveData()` merges: the keys you pass are set, the keys you omit keep their stored value.

<a name="choosing-a-read"></a>
### Choosing a Read

`data()` merges the steps in configured order, so a key stored by two steps resolves to the
later one. That is the right default for a form and for business logic that wants the whole
picture: the later step is the one the user filled most recently.

Use `stepsData()` when the merge would hide something — a last step assembling an order, or a
handler that needs to know what each step actually holds:

```php
$steps = $this->wizard->stepsData();
// ['branch' => [...], 'items' => [...], 'buyer' => [...]]
```

Use `stepData()` when you wrote to a specific step and want to read it back from that step.
Reading through `data()` can return a different step's value for the same key, and the write
looks like it did nothing. It is the read half of `saveData()`:

```php
$this->wizard->saveData(['user' => $user], 'buyer');

$buyer = $this->wizard->stepData('buyer');
```

> [!NOTE]
> The 1.x component exposed a single flat session array, and a page read each value from the
> one place it lived, so nothing could overwrite anything. `stepsData()` is that guarantee in
> the 2.0 model. Merge it yourself, and name your rules explicitly — the plugin cannot tell
> an intentional override from two steps using the same name for different things.

Next, continue to [Programmatic Control](programmatic-control.md).
