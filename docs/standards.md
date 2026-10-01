# Standards and References

- [Introduction](#introduction)
- [Double Submission](#double-submission)
- [Error Association and Summary](#error-association-and-summary)
- [WCAG Criteria Shipped](#wcag-criteria-shipped)
- [A Trade-off, not a Clean Pass](#a-trade-off-not-a-clean-pass)
- [Cache and Personal Data](#cache-and-personal-data)
- [Styling](#styling)
- [Idempotency](#idempotency)
- [CSRF](#csrf)

<a name="introduction"></a>
## Introduction

The other pages describe what the plugin does. This one records the standard behind each
behavior, so a design decision can be checked rather than taken on trust.

<a name="double-submission"></a>
## Double Submission

- [WHATWG HTML #5312](https://github.com/whatwg/html/issues/5312) — the web platform does not
  prevent double submissions. Every application needs its own guard.
- [GOV.UK](https://design-system.service.gov.uk/patterns/questions-page-validation-errors/)
  and [UK Parliament](https://designsystem.parliament.uk/button-component/) design systems —
  do not disable buttons. Disabled controls are not announced and do not say why they cannot
  be used.
- WCAG 3.3.1, and the WebAIM debate on error identification (Matt King) — submitting
  repeatedly to walk through errors is an efficient strategy for some users. A disabled button
  takes it away.
- [MDN `aria-busy`](https://developer.mozilla.org/en-US/docs/Web/Accessibility/ARIA/Reference/Attributes/aria-busy)
  — marks the region being updated while a submission is in flight.

Applied: the built-in script cancels repeat submits of the same form in the capture phase, so
no second request is created; the form carries `aria-busy` in flight; the button is never
disabled; and the stylesheet styles the `wn-loading` class that `data-attach-loading` adds,
with a `prefers-reduced-motion` fallback.

<a name="error-association-and-summary"></a>
## Error Association and Summary

- WAI-ARIA technique [ARIA1](https://www.w3.org/WAI/ARIA/apg/patterns/alert/)
  (`aria-describedby`) — ties each error to the control it belongs to.
- [ARIA19](https://www.w3.org/WAI/WCAG22/Techniques/aria/ARIA19) (`role="alert"`) — announces
  an error as soon as it appears. **The technique is satisfied by the form-level summary, not
  by the field bags.** Snowboard's `FormValidation` removes every `[data-validate-error]`
  element at startup and leaves a comment placeholder, re-inserting the empty element only
  when a message arrives. The field bag is therefore added to the DOM with its message rather
  than being present at load, which is not what ARIA19 describes. The plugin keeps
  `role="alert"` on it anyway: the bags are announced on injection by the screen readers that
  implement it, and the `aria-describedby` association plus the focus move already reach a
  user who is not told. The form-level container, which has no `data-validate-error`, is
  present from the load and does satisfy the technique.
- [GOV.UK error summary](https://design-system.service.gov.uk/components/error-summary/) —
  form-level errors, meaning business rules with no field in the DOM, render in a container
  that receives focus when no field error applies.

<a name="wcag-criteria-shipped"></a>
## WCAG Criteria Shipped

- [1.3.5 Identify Input Purpose](https://www.w3.org/WAI/WCAG22/Understanding/identify-input-purpose.html)
  (technique H98) — the field contract accepts an `attributes` array, so `autocomplete` is
  declarable on every field that collects user data.
- [2.2.1 Timing Adjustable](https://www.w3.org/WAI/WCAG22/Understanding/timing-adjustable.html)
  — the inactivity timeout is configurable per wizard and disablable with `ttl = 0`.
- [3.3.3 Error Suggestion](https://www.w3.org/WAI/WCAG22/Understanding/error-suggestion.html)
  — per-step messages and application language overrides let a message state how to fix the
  problem.
- WAI multi-page forms tutorial — the step indicator follows its structure, and the position
  and step name are exposed to the layout for the page title.

<a name="a-trade-off-not-a-clean-pass"></a>
## A Trade-off, not a Clean Pass

[3.3.7 Redundant Entry](https://www.w3.org/WAI/WCAG22/Understanding/redundant-entry.html) is
Level A, and the plugin does not meet it as written. Returning to a step you already submitted
re-fills the values you entered **in that step**. What it does not do is keep the data of the
*later* steps: wipe-on-back clears them.

That is a deliberate privacy decision, not an oversight, and the criterion's own scope is under
discussion in the working group, where several members argue it covers going back and
refreshing, not only moving forward — see
[w3c/wcag#4481](https://github.com/w3c/wcag/issues/4481). `retainData` makes the later steps
survive and the completed ones clickable, which is the compliant configuration at the cost of
the privacy default.

<a name="cache-and-personal-data"></a>
## Cache and Personal Data

- [OWASP WSTG, browser cache weaknesses](https://wstg.owasp.org/latest/4-Web_Application_Security_Testing/04-Authentication/06-Browser_Cache_Weaknesses/)
  and the [HTTP Headers Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html)
  — a page that displays personal data must instruct the browser not to retain it. Step pages
  answer `Cache-Control: no-store`, so the values a user typed are not left in the browser
  cache nor recovered with the Back button.
- [Chrome, bfcache and `no-store`](https://developer.chrome.com/docs/web-platform/bfcache-ccns)
  — the directive is applied to the page render only, not to the submit responses. An XHR that
  also answers `no-store` evicts the page from the back/forward cache on every submission, for
  no privacy gain, because those responses carry no user data. The cost of the render is also
  near zero: Chrome completed the rollout that allows `no-store` pages in the bfcache in
  March and April 2025, the wizard navigates by full page loads, and it touches its session
  cookie on every request, which evicts the page from the bfcache regardless.

> [!NOTE]
> The header is applied in the render lifecycle only. The value the browser ends up storing is
> `no-store, private`, because Symfony's `ResponseHeaderBag` appends `private` when the response
> has no `s-maxage` or `public` directive. `no-store` is the one that governs.

<a name="styling"></a>
## Styling

- [CSS cascade layers](https://developer.mozilla.org/en-US/docs/Web/CSS/@layer) — every rule
  of the stylesheet lives in `@layer wizard`, so an unlayered theme stylesheet always wins,
  without `!important`.
- [CSS custom properties](https://developer.mozilla.org/en-US/docs/Web/CSS/--*) as design
  tokens, named `--wizard-*`.
- [`prefers-color-scheme`](https://developer.mozilla.org/en-US/docs/Web/CSS/@media/prefers-color-scheme)
  and [`prefers-reduced-motion`](https://developer.mozilla.org/en-US/docs/Web/CSS/@media/prefers-reduced-motion)
  are respected without configuration.

<a name="idempotency"></a>
## Idempotency

[Stripe idempotency keys](https://docs.stripe.com/api/idempotent_requests) — no client-side
guard can rule out a concurrent duplicate, so the server-side control belongs to you. The
`commit()` hook runs exactly once per successful submit, which is the point at which a
duplicate would duplicate, and a side effect there should carry its own idempotency key.

<a name="csrf"></a>
## CSRF

Winter CMS [enables CSRF protection by default](https://wintercms.com/docs/v1.2/docs/services/security#csrf-protection)
and validates the no-JavaScript POST path. The step shell renders `{{ form_token() }}` and a
`_handler` field, which covers it. The Snowboard AJAX path sends `X-WINTER-REQUEST-HANDLER` as
a header, which a cross-site attacker cannot forge, so a standalone `data-request` button
works without an explicit token.
