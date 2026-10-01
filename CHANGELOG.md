# Changelog

All notable changes are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow Semantic Versioning.

## [2.0.0] - 2026-09-30

### Breaking

- Declarative configuration: the wizard is declared with the fluent builder or the component properties. The 1.x array config with per-page handlers is gone.
- One generic handler (`wizard::onNext`) for every step. The rules applied are those of the step in the URL, not the handler name requested.
- Namespaced session state (`zimudec.wizard.<page>.<alias>`). Two wizards on different pages never share state. User data and internal metadata are stored separately. The flat 1.x key is gone, so wizards in progress end on a deploy restart.
- Field whitelisting: each step persists only its rule keys and its declared fields. Uploaded files are never serialized. A rule on a parent key covers everything under it only while no rule is declared inside it.
- No jQuery, no Bootstrap: Snowboard frontend, plugin stylesheet in a cascade layer, framework-native redirects and validation exceptions. `exit()`, `->send()` and `dump()` are gone.
- PHP 8.2+ and Winter CMS 1.2+ required.

### Added

- Server-side gating of unreachable steps. An unknown step falls back to the last valid one.
- `->revalidateOnSubmit()` revalidates the previous steps on submit only. It is the recommended choice, and like `->revalidate()` it runs only when a step declares it. Both use the previous steps' own rules.
- Graceful bounce of a failed revalidation: back to the first incomplete step, keeping the data of earlier steps.
- Three-phase submit order. Current field errors reach the user as field errors before the revalidation bounce can apply.
- Double-submission guard in the built-in script. No second request while one is in flight, no disabled buttons, `aria-busy` on the form. The form is unlocked on every outcome, including a cancelled request.
- Nested data and Laravel wildcard rules (`items.*.quantity`) persist with their nesting intact. A rule whose data does not arrive is not an error.
- Session and navigation API for auxiliary AJAX handlers: `data()`, `stepsData()`, `stepData()`, `meta()`, `stepCurrent()`, `saveData()`, `forgetData()`, `requireData()`, `flush()`, `redirectTo()`, `jumpTo()`.
- `forgetData()` removes named keys from a step, or from every step when no step is given. A renamed field no longer keeps its old key for the lifetime of the wizard.
- `requireData()` guards an auxiliary handler and returns its data in one call. The check cannot be left out of a handler that needs the data.
- `needsRedirect()` tells a handler which of the two it got, so the page never names the class the guard returns.
- Deep-link entries preserve the query string across the internal redirect.
- Cycle events for other plugins: `wizard.beforeValidate`, `wizard.afterCommit`, `wizard.stepCompleted`. `afterCommit` fires before the step is persisted, `stepCompleted` after it.
- Step pages answer `Cache-Control: no-store`. The values a user typed are not left in the browser cache, nor recovered with the Back button.
- Level 0 fields are persisted and re-filled when the user returns, without gaining any validation rule.
- Sliding 30-minute inactivity TTL, configurable per wizard and globally.
- Wipe-on-back by default. `retainData` for an editable stepper.
- `->keepSteps(['step'])` keeps the data of the steps you name when the user goes back. Every other later step is still cleared.
- Wizard-wide settings from the builder: `->ttl()`, `->wipeOnBack()`, `->retainData()`, `->encrypted()` and `->keepSteps()`, next to `->finishUrl()`. A setting the page leaves alone falls back to the component property.
- Data cleared on finish, with `keepData()` or `retainData` to defer it. Opt-in encryption of the persisted data.
- Custom step views (`->view()`) and theme overrides of every wizard partial. The step view renders inside the element that carries the design tokens.
- Per-step validation messages, `field.rule` syntax. Generic messages overridable from the application `lang/`.
- Translated interface strings (en, es, fr) and translated exceptions for invalid configurations.
- Accessible step indicator (`aria-current="step"`), error focus script and GOV.UK-style error summary.
- Assets through the framework Asset Combiner: fingerprinted URLs, 1-year cache, minified in production, `defer`.
- Test suite: unit tests and acceptance tests of the three configuration levels.
- Documentation rebuilt on the architecture and conventions of the Laravel documentation. A master index grouped by progression, one topic per page, an index of anchors and an introduction on every page, and typed callouts. New pages for installation, configuration, validation, navigation and session. The upgrade guide replaces the migration guide.

## [1.0.7]

Previous release: array-config wizard with per-page handlers, jQuery frontend, flat session state.
