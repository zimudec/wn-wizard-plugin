# Privacy

- [Introduction](#introduction)
- [What the Wizard Does With the Data](#what-the-wizard-does-with-the-data)
    - [Sliding Inactivity Timeout](#sliding-inactivity-timeout)
    - [Wipe on Back](#wipe-on-back)
    - [Cleared on Finish](#cleared-on-finish)
    - [Opt-in Encryption](#opt-in-encryption)
    - [Whitelisted Persistence](#whitelisted-persistence)
    - [Not Retained by the Browser](#not-retained-by-the-browser)
- [Privacy Checklist](#privacy-checklist)

<a name="introduction"></a>
## Introduction

The wizard collects personal data and holds it in the session between requests, so what it
does with that data is part of its contract rather than an implementation detail. This page
states that contract. For the mechanism behind each item, see
[Session](session.md#what-persists).

Every item is configurable, and the defaults are the conservative choice.

<a name="what-the-wizard-does-with-the-data"></a>
## What the Wizard Does With the Data

<a name="sliding-inactivity-timeout"></a>
### Sliding Inactivity Timeout

The state expires after 30 minutes without activity, measured server-side and refreshed on
every render and every submit. When it expires, the next interaction removes it and restarts
the flow.

Configure it per wizard with the `ttl` property or `->ttl()`, or for the whole application in
[Global Defaults](installation.md#global-defaults). `0` disables the timeout; leaving it
undeclared takes the global value. Disabling it is not recommended — see
[2.2.1 Timing Adjustable](standards.md#wcag-criteria-shipped) for the accessibility
requirement it answers to.

<a name="wipe-on-back"></a>
### Wipe on Back

Going back to a previous step clears the data of every later step. The completed steps in the
indicator are not clickable, so the user is not invited to trigger it by accident.

`keepSteps` names the steps whose data survives, and clears every other later step.
`retainData` is the opposite trade: it keeps every later step and makes the completed steps
clickable. See [Going Back](session.md#going-back).

<a name="cleared-on-finish"></a>
### Cleared on Finish

Submitting the last step removes the whole state at the end of the request. The data is
available for that request, and not for the next one, so reloading the last page restarts the
wizard.

A step that declares `->keepData()`, or a wizard with `retainData` on, defers the deletion
until the next restart of the flow. See [Cleared on Finish](session.md#cleared-on-finish).

<a name="opt-in-encryption"></a>
### Opt-in Encryption

The persisted data can be encrypted with the application key. Without it, the wizard uses the
CMS's ordinary session storage, and what protects it is whatever protects the session itself.

Reading tolerates data written before encryption was enabled and data written with a previous
`APP_KEY`: an undecryptable value is returned as stored, the form shows garbage rather than
the request failing, and the user's next submit overwrites it. No crash and no leak.

<a name="whitelisted-persistence"></a>
### Whitelisted Persistence

A step persists only what it declared: the keys of its rules, including Laravel's wildcard
notation, and its declared field names. Every other field of the request is discarded, and so
is every uploaded file — files are never serialized into the session.

A field declared without a rule is persisted and never validated. A rule on a parent key
covers everything under it **only while no rule is declared inside it**; declare
`items.*.quantity` as well and the rules below define what is kept, so a crafted
`items.0.admin` is discarded like any other undeclared key.

<a name="not-retained-by-the-browser"></a>
### Not Retained by the Browser

The page of a step re-populates the values the user already typed, so it carries personal data
in its response. It therefore answers `Cache-Control: no-store`: the browser does not keep the
HTML in its cache, and the Back button does not bring the data back.

The submit responses do not carry user data and are not marked. See
[Cache and Personal Data](standards.md#cache-and-personal-data) for why, including the
back/forward cache trade-off.

<a name="privacy-checklist"></a>
## Privacy Checklist

Before publishing a wizard that collects personal data, confirm three decisions:

1. The **inactivity timeout** suits the sensitivity of the data. The default is 30 minutes.
2. **`wipeOnBack`** (the default) or **`retainData`** matches the promise you make to the user
   about what is kept when they go back.
3. **`encrypted`** is on when the session storage is not already encrypted.

Next, see [Accessibility](accessibility.md).
