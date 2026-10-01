# Accessibility

- [Introduction](#introduction)
- [What Ships With the Component](#what-ships-with-the-component)
- [What Only You Can Provide](#what-only-you-can-provide)

<a name="introduction"></a>
## Introduction

Most of the accessibility work of a multi-page form is either the framework's or the browser's,
and the wizard's job is to not undo it. What follows is what you get without doing anything,
and the two things the plugin cannot decide for you. The reasoning behind each behavior, with
its citation, is in [Standards and References](standards.md).

<a name="what-ships-with-the-component"></a>
## What Ships With the Component

- **The step indicator marks the current step** with `aria-current="step"`, and the
  `completed`, `current` and `pending` states are conveyed by class names a theme can style
  differently.
- **Field errors are programmatically associated** with their control: the input carries
  `aria-describedby` pointing at the bag that holds the message. On a failed AJAX submit the
  script marks each invalid control with `aria-invalid="true"` and moves focus to the first
  one, following the [WAI-ARIA form errors pattern](https://www.w3.org/WAI/ARIA/apg/patterns/alert/).
- **Business-rule errors with no field in the DOM** land in a form-level container that is
  present from the page load and takes focus when it is the only error, following the
  [GOV.UK error summary](https://design-system.service.gov.uk/components/error-summary/)
  pattern.
- **Repeated submissions are ignored** while one is in flight. The second click or `Enter` is
  cancelled client-side before Snowboard can start a second request, and the form carries
  `aria-busy` while the request runs. The submit button is **never disabled**, because
  disabled controls are not announced and do not say why they cannot be used.
- **Returning to a completed step re-fills the saved values** (WCAG 3.3.7 *Redundant Entry*):
  nobody re-types what the wizard already stored. The data of the *later* steps is cleared on
  the way back unless you set `retainData` — a deliberate privacy trade-off, described in
  [Going Back](session.md#going-back).
- **The inactivity timeout is adjustable** per wizard and can be disabled with `ttl = 0`
  (WCAG 2.2.1 *Timing Adjustable*).

<a name="what-only-you-can-provide"></a>
## What Only You Can Provide

**Declare the purpose of the fields that collect information about the user.** WCAG 1.3.5
*Identify Input Purpose* asks for it, browsers act on it, and so do assistive technologies:

```php
->fields([['name' => 'buyer_email', 'label' => 'Email', 'attributes' => ['autocomplete' => 'email']]])
```

**Write error messages that say how to fix the problem.** WCAG 3.3.3 *Error Suggestion* asks
for a suggestion whenever it is available, and "This field is required" is not one. See
[Per-step Messages](customization.md#per-step-messages).

**Put the progress in the page title.** The WAI multi-page forms tutorial asks for the
position first, then the step name; both values are exposed to the layout — see
[View Variables](customization.md#view-variables).
