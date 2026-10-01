# Navigation

- [Introduction](#introduction)
- [The Step in the URL](#the-step-in-the-url)
- [Gating](#gating)
    - [Before the First Step](#before-the-first-step)
    - [Unknown and Unreachable Steps](#unknown-and-unreachable-steps)
    - [After the Last Step](#after-the-last-step)
    - [Expired and Restarted Flows](#expired-and-restarted-flows)
- [Building the URLs](#building-the-urls)
- [The Query String](#the-query-string)
- [The Step Indicator](#the-step-indicator)
- [Navigation API](#navigation-api)

<a name="introduction"></a>
## Introduction

A step of the wizard is a URL, and the wizard resolves the step from that URL on the server
before it renders anything. Access to a step the user has not reached is refused with a
redirect, not with a form: the flow cannot be walked by editing the address bar, and a
bookmarked or shared link lands on the last step the user is allowed to see.

Every navigation URL is derived from the page URL and the configured step codes, so the
component needs no routing configuration and the page needs only the `:step?` parameter
described in [Installation](installation.md#declaring-the-step-parameter).

<a name="the-step-in-the-url"></a>
## The Step in the URL

The step code is the `:step` parameter of the page URL. Given a page at `/checkout/:step?` and
the codes `branch`, `items`, `buyer`, `review`, the four addresses are:

| URL | Step |
|---|---|
| `/checkout/branch` | 1 — Pick your store |
| `/checkout/items` | 2 — Pick your products |
| `/checkout/buyer` | 3 — Buyer details |
| `/checkout/review` | 4 — Review and pay |

The code is what you pass to every navigation call, and what `wizard.steps[].code` holds in
the view. The position in that list is what the session tracks.

<a name="gating"></a>
## Gating

The session holds a progress pointer: the index of the last step the user validated. A fresh
wizard has pointer `0`, so only the first step is reachable; submitting it moves the pointer to
`1`, and the second step becomes reachable.

| Request | Result |
|---|---|
| No `:step` parameter | Redirect to the first step |
| A step at or before the pointer | Rendered |
| A step after the pointer | Redirect to the pointer's step |
| A code that matches no step | Redirect to the pointer's step |

The redirect is a framework-native `RedirectResponse`: `X_WINTER_REDIRECT` for an AJAX
request, a plain `302` for a form post without JavaScript.

<a name="before-the-first-step"></a>
### Before the First Step

A request without a `:step` parameter is redirected to the first step, keeping the query
string. Enter the flow at `/checkout` and the browser lands on `/checkout/branch?ref=...`,
which is what makes a deep link into the middle of a flow expressible — see
[Deep Link with Prefill](programmatic-control.md#deep-link-with-prefill).

<a name="unknown-and-unreachable-steps"></a>
### Unknown and Unreachable Steps

Both cases resolve to the same place, the last valid step. A code that matches nothing and a
step the user has not reached are treated identically, and neither reports an error to the
user: the wizard has no notion of a 404 for a step, only of a position it may serve.

<a name="after-the-last-step"></a>
### After the Last Step

Submitting the last step stores its data, clears the state, and redirects to `finishUrl`. The
data is available for the whole request, so a confirmation page that reads it during the same
render still finds it; the next request no longer does.

Reloading the last page therefore restarts the flow. When the confirmation page needs the data
across requests, declare `->keepData()` on the last step: the state survives until the next
restart of the flow. See [Cleared on Finish](session.md#cleared-on-finish).

<a name="expired-and-restarted-flows"></a>
### Expired and Restarted Flows

Two conditions flush the state and restart at the first step: an inactivity timeout, and a
state marked finished. Both are checked on the page render and again on the submit, so a form
posted long after the timeout is not applied to a stale state.

The flush is total. There is no path that revives an expired wizard, and the user is never
shown a step with data that no longer has a session to belong to.

<a name="building-the-urls"></a>
## Building the URLs

The component builds each URL from the page file name and the step code, so the links work on
any host, scheme or subdirectory:

```php
$url = \Cms\Classes\Page::url($this->page->fileName, ['step' => 'items']);
// https://example.com/checkout/items
```

The view receives them ready to use:

| Variable | What it holds |
|---|---|
| `wizard.urls.next` | The next step's URL, or `null` on the last step |
| `wizard.urls.prev` | The previous step's URL, or `null` on the first step |
| `wizard.urls.finish` | The configured `finishUrl` |
| `wizard.steps[].url` | Each step's own URL |

A `null` in `next` is what turns the submit button into a Finish button, so override the
button partial only if you want to change that rule.

<a name="the-query-string"></a>
## The Query String

The automatic redirects differ in what they preserve, and the difference is deliberate:

| Redirect | Query string |
|---|---|
| To the first step (no `:step`, expired, unknown, unreachable) | Preserved |
| To the next step after a submit | **Dropped** |
| To `finishUrl` | **Dropped** |
| From `redirectTo()` or `jumpTo()` | Only the parameters you pass |

A deep-link campaign enters through the wizard root, and its parameters must survive the
internal bounce, so the first row preserves them. The submit redirects drop them on purpose:
the step has just been answered, and a stale campaign reference should not follow the user
into a step it does not describe. When an advance must carry a parameter, return a
`jumpTo()` with the query instead of relying on the automatic path.

<a name="the-step-indicator"></a>
## The Step Indicator

The default shell renders the step list from `wizard.steps`. Each entry carries a `state`:

| State | When | Markup |
|---|---|---|
| `completed` | Before the current step | A link when `retainData` is on, otherwise a `<span>` |
| `current` | The step being served | A `<span>` with `aria-current="step"` |
| `pending` | After the current step | A `<span>` |

A completed step is not clickable by default, which is the same decision as wipe-on-back: an
editable stepper invites the user to revisit a step, and revisiting a step is what clears the
later data. `retainData` changes both together, so the indicator and the retention always
agree.

> [!NOTE]
> `state` is computed from the step being served, not from the progress pointer. After a
> `jumpTo('review')` the first three steps render as `completed` even though the user did not
> fill them. That is correct for a prefilled deep link, and worth knowing before you derive
> business logic from the state.

To render progress somewhere else, see [View Variables](customization.md#view-variables).

<a name="navigation-api"></a>
## Navigation API

Two calls, both returning a `RedirectResponse` and both failing fast when the step code does
not exist.

| Call | What it does |
|---|---|
| `->redirectTo($stepCode, $query = [])` | Redirect without touching the stored data or the progress pointer. The render guard of a page lifecycle: "the cart is empty, back to step 1" |
| `->jumpTo($stepCode, $data = [], $query = [])` | Persist `$data`, unlock every step up to the target, and redirect to it. The deep-link and skip API |

`redirectTo()` is what an `onEnd()` guard and an AJAX handler return. `jumpTo()` is what a
`handler()` returns when it wants to choose the destination, and what an `onInit()` uses to
prefill a flow — see [Programmatic Control](programmatic-control.md).

> [!NOTE]
> `jumpTo()` unlocks but does not validate. The rules of the skipped steps never run, and only
> the target step's rules run on its own submit. Prefill what the later steps read, including
> their derived data, or declare `->revalidateOnSubmit()` on the target so a missing
> requirement lands the user on the step that owns it.

Next, continue to [Session](session.md).
