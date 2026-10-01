<?php

namespace Zimudec\Wizard\Components;

use Cms\Classes\ComponentBase;
use Event;
use Illuminate\Http\RedirectResponse;
use Redirect;
use Zimudec\Wizard\Support\ConfigurationException;
use Zimudec\Wizard\Support\SessionStore;
use Zimudec\Wizard\Support\StepResolver;
use Zimudec\Wizard\Support\StepValidator;
use Zimudec\Wizard\Support\WizardConfig;

/**
 * Thin orchestrator of the wizard: resolves the step from the URL parameter,
 * gates access server-side, validates and stores the submitted data and
 * advances the flow. Redirects and errors are delegated to the framework
 * (RedirectResponse → X_WINTER_REDIRECT; ValidationException → error fields
 * for Snowboard); the component never terminates the request abruptly.
 */
class Wizard extends ComponentBase
{
    protected ?WizardConfig $config = null;
    protected ?array $builderConfig = null;
    protected ?SessionStore $store = null;
    protected ?StepResolver $resolver = null;
    protected ?int $currentPosition = null;

    /**
     * Declares the configuration of the wizard from the page (levels 1 and
     * 2): the closure receives the declarative builder and may define steps
     * with fields, rules, messages and hooks. Takes precedence over the
     * component properties (level 0).
     */
    public function define(\Closure $configurator): void
    {
        $builder = new \Zimudec\Wizard\Support\Builder();
        $configurator($builder);
        $this->builderConfig = $builder->toArray();
    }
    protected ?StepValidator $validator = null;

    public function componentDetails(): array
    {
        return [
            'name' => 'zimudec.wizard::lang.component.name',
            'description' => 'zimudec.wizard::lang.component.description'
        ];
    }

    public function defineProperties(): array
    {
        return [
            'steps' => [
                'title' => 'zimudec.wizard::lang.properties.steps.title',
                'description' => 'zimudec.wizard::lang.properties.steps.description',
                'default' => '',
                'type' => 'string',
            ],
            'titles' => [
                'title' => 'zimudec.wizard::lang.properties.titles.title',
                'description' => 'zimudec.wizard::lang.properties.titles.description',
                'default' => '',
                'type' => 'string',
            ],
            'fields' => [
                'title' => 'zimudec.wizard::lang.properties.fields.title',
                'description' => 'zimudec.wizard::lang.properties.fields.description',
                'default' => '',
                'type' => 'string',
            ],
            'finishUrl' => [
                'title' => 'zimudec.wizard::lang.properties.finish_url.title',
                'description' => 'zimudec.wizard::lang.properties.finish_url.description',
                'default' => '/',
                'type' => 'string',
            ],
            'ttl' => [
                'title' => 'zimudec.wizard::lang.properties.ttl.title',
                'description' => 'zimudec.wizard::lang.properties.ttl.description',
                'default' => '',
                'type' => 'string',
            ],
            'wipeOnBack' => [
                'title' => 'zimudec.wizard::lang.properties.wipe_on_back.title',
                'description' => 'zimudec.wizard::lang.properties.wipe_on_back.description',
                'default' => true,
                'type' => 'checkbox',
            ],
            'retainData' => [
                'title' => 'zimudec.wizard::lang.properties.retain_data.title',
                'description' => 'zimudec.wizard::lang.properties.retain_data.description',
                'default' => false,
                'type' => 'checkbox',
            ],
            'encrypted' => [
                'title' => 'zimudec.wizard::lang.properties.encrypted.title',
                'description' => 'zimudec.wizard::lang.properties.encrypted.description',
                'default' => false,
                'type' => 'checkbox',
            ],
            'keepSteps' => [
                'title' => 'zimudec.wizard::lang.properties.keep_steps.title',
                'description' => 'zimudec.wizard::lang.properties.keep_steps.description',
                'default' => '',
                'type' => 'string',
            ],
            'css' => [
                'title' => 'zimudec.wizard::lang.properties.css.title',
                'description' => 'zimudec.wizard::lang.properties.css.description',
                'default' => true,
                'type' => 'checkbox',
            ],
            'js' => [
                'title' => 'zimudec.wizard::lang.properties.js.title',
                'description' => 'zimudec.wizard::lang.properties.js.description',
                'default' => true,
                'type' => 'checkbox',
            ],
        ];
    }

    /**
     * Page lifecycle: resolves the current step, gates the access server
     * side and exposes the wizard state to the view (only user data).
     */
    public function onRun(): ?RedirectResponse
    {
        // The step HTML repopulates the values the user already entered, so
        // the response carries PII: it must not be retained in the browser
        // cache nor be recoverable with the Back button (OWASP WSTG, browser
        // cache weaknesses). The framework default is "no-cache, private",
        // which still lets the browser store the bytes on disk.
        //
        // Applied here and not in onNext(): the submit response carries no
        // user data (the redirect body is empty, the 406 payload holds the
        // messages the developer defined), and a no-store XHR response evicts
        // the page from the back/forward cache on every submission for
        // nothing. Cost is near zero: Chrome has allowed no-store pages in
        // the bfcache since March-April 2025, and the wizard is already
        // full-page navigation (the back button round-trips to the server).
        $this->controller->setResponseHeader('Cache-Control', 'no-store');

        // Assets go through the framework Asset Combiner: the URL carries a
        // content fingerprint (hash + mtime) that changes automatically when
        // the asset changes, and the combined response carries 1-year cache
        // headers; it is minified in production when debug is off (the
        // default cms.enableAssetMinify: null). The script is deferred so it
        // never blocks HTML parsing (MDN/web.dev: non-critical scripts are
        // loaded with defer). Both assets are opt-out: a theme may provide
        // its own styling (css) or its own focus behaviour over the same DOM
        // events (js).
        if ($this->property('js')) {
            $this->addJs(['assets/js/wizard.js'], ['defer']);
        }
        if ($this->property('css')) {
            $this->addCss(['assets/css/wizard.css']);
        }
        $config = $this->resolveConfig();
        $store = $this->makeStore($config);
        $resolver = $this->makeResolver($config);

        $this->ensureStepParamDeclared();

        // A finished wizard restarts on the next interaction.
        if ($store->isFinished() || $store->isExpired($this->effectiveTtl($config))) {
            $store->flush();

            return $this->redirectToStep($resolver, 0);
        }

        // Without the URL step parameter, land on the first step.
        if (!$this->param('step')) {
            return $this->redirectToStep($resolver, 0);
        }

        $position = $resolver->position((string) $this->param('step'));

        // Unknown or not-yet-reachable steps fall back to the last valid one.
        if ($position === null || $position > $store->stepCurrent()) {
            return $this->redirectToStep($resolver, $store->stepCurrent());
        }

        $step = $resolver->step($position);

        // Revalidation on entry (the 1.x validatePrevSteps semantics): a
        // step that declares revalidate also gates the render — the data of
        // the previous steps is revalidated with their rules BEFORE the
        // form is shown, so the user never fills a form that cannot be
        // advanced. The bounce drops the entry query on purpose: re-running
        // a deep-link campaign with the same invalid prefill would loop the
        // user between the landing and the revalidation.
        if ($step->revalidate && ($failing = $this->revalidatePrevious($store, $resolver, $position)) !== null) {
            return $this->revalidateBounce($store, $resolver, $failing);
        }

        // Going back wipes the data of the later steps (privacy default);
        // with retainData the data of later steps is retained (editable
        // stepper with clickable completed steps). keepSteps narrows the wipe
        // to the steps the page did not name. The revalidation bounce below
        // wipes WITHOUT them: that wipe exists because the data is invalid,
        // so keeping the failing step's data would defeat the correction.
        if ($position < $store->stepCurrent() && $config->wipeOnBack && !$config->retainData) {
            $store->wipeFrom($step->code, $config->keepSteps);
        }

        $store->touch();

        $this->exposeToView($config, $store, $resolver, $position);

        return null;
    }

    /**
     * Plain redirect to a step URL, carrying the query string of the current
     * request. Deep-link campaigns enter through the wizard root URL (or
     * through a gated step) and the entry parameters must survive the
     * internal bounce — the page's onInit deep-link pattern reads them
     * after it. The submit pipeline does NOT do this: its automatic
     * next-step redirect drops the query on purpose (jumpTo is the one
     * that preserves it).
     */
    protected function redirectToStep(StepResolver $resolver, int $position): RedirectResponse
    {
        $query = request()->getQueryString();
        $url = $resolver->urlFor($position);

        return Redirect::to($query ? $url . '?' . $query : $url);
    }

    /**
     * Single submit handler of every step. The rules applied are those of the
     * step currently in the URL, never the handler name requested by the
     * client. Returns a RedirectResponse (the framework converts it into
     * X_WINTER_REDIRECT for AJAX and a plain 302 for no-JS POSTs).
     */
    public function onNext(): RedirectResponse
    {
        $config = $this->resolveConfig();
        $store = $this->makeStore($config);
        $resolver = $this->makeResolver($config);
        $validator = new StepValidator();

        $this->ensureStepParamDeclared();

        // An expired wizard restarts on the next interaction. (A finished
        // wizard is not restarted here: the submit of the last step is what
        // completes the wizard.)
        if ($store->isExpired($this->effectiveTtl($config))) {
            $store->flush();

            return $this->redirectToStep($resolver, 0);
        }

        $position = $resolver->position((string) $this->param('step'));

        // Unknown or not-yet-reachable steps cannot be submitted; fall back
        // to the last valid step.
        if ($position === null || $position > $store->stepCurrent()) {
            return $this->redirectToStep($resolver, $store->stepCurrent());
        }

        $step = $resolver->step($position);

        // Whitelist: only fields declared in the step rules persist; files
        // and unknown fields are discarded.
        $input = $validator->filterInput(request()->all(), $step);

        $accumulated = $store->mergedData();

        // Phase 1 — the base rules of the current step run first (cheap):
        // the field errors are collected without throwing.
        $baseErrors = $validator->validateBase($step, array_merge($accumulated, $input));

        // Phase 2 — revalidation of the previous steps (the data of previous
        // steps is revalidated with the rules of their original steps; a
        // failure behaves like a "back" to the failing step). It is SKIPPED
        // when the current step already failed on its base rules: the field
        // errors reach the user before paying the revalidation cost, and a
        // re-run after fixing them catches the broken previous data. This
        // bounce drops the entry query on purpose: the previous data was
        // judged invalid, and re-running a deep-link campaign with the same
        // invalid prefill would loop the user between the landing and the
        // revalidation.
        if (
            $baseErrors === []
            && ($step->revalidate || $step->revalidateOnSubmit)
            && ($failing = $this->revalidatePrevious($store, $resolver, $position)) !== null
        ) {
            return $this->revalidateBounce($store, $resolver, $failing);
        }

        // Cycle event: before the closure phase. A listener may throw a
        // ValidationException to block the advance.
        Event::fire('wizard.beforeValidate', [$this, $step, array_merge($accumulated, $input)]);

        // Phase 3 — the extra validation of the current step runs after the
        // revalidation, with the derived data of the previous steps fresh
        // and the base errors pre-seeded (the control channels ride on any
        // failing step).
        $accumulated = $store->mergedData();
        $derived = $validator->validateExtra($step, $input, $accumulated, $baseErrors);

        // Commit hook runs exactly once per successful submit.
        $validator->commit($step, array_merge($input, $derived), $accumulated);

        // Per-step handler runs exactly once per successful submit. A
        // RedirectResponse returned by the handler becomes the destination
        // of the submit (conditional skips): the pipeline still persists the
        // data but leaves the progress pointer alone — the handler owns the
        // navigation (e.g. jumpTo() already set the pointer it needs).
        $handlerResult = $step->handler !== null
            ? ($step->handler)(array_merge($input, $derived), $store->mergedData())
            : null;

        // Cycle event: after the commit hook.
        Event::fire('wizard.afterCommit', [$this, $step, $store->mergedData()]);

        // Persist whitelisted data. The progress pointer is advanced only in
        // the automatic path: a handler that redirects owns the navigation.
        $store->saveStepData($step->code, array_merge($input, $derived));
        $store->touch();

        // The handler took over the navigation: the wizard state stays alive
        // (a payment flow, for instance, needs it while the gateway calls
        // back), and the automatic finish/next logic is skipped.
        if ($handlerResult instanceof RedirectResponse) {
            Event::fire('wizard.stepCompleted', [$this, $step, $store->mergedData()]);

            return $handlerResult;
        }

        $store->setStepCurrent($position + 1);

        $nextUrl = $resolver->nextUrl($position);

        // Completing the last step ends the flow: the state is cleared at the
        // end of the request (a keepData step defers the deletion until the
        // next restart of the flow) and the browser lands on the finish URL.
        if ($nextUrl === null) {
            if ($config->retainData || $step->keepData === true) {
                $store->markFinished();
            } else {
                $store->flush();
            }

            Event::fire('wizard.stepCompleted', [$this, $step, $store->mergedData()]);

            return Redirect::to($config->finishUrl);
        }

        // Cycle event: the step completed.
        Event::fire('wizard.stepCompleted', [$this, $step, $store->mergedData()]);

        return Redirect::to($nextUrl);
    }

    /**
     * Render lifecycle: a step may declare its own view (a partial of the
     * page or theme); it takes precedence over the default step shell.
     *
     * The view is wrapped in the element that carries the design tokens.
     * Every rule of the stylesheet consumes a `var(--wizard-*)`, and the
     * tokens are declared on `.wizard` itself, so a view that omitted that
     * wrapper would lose the tokens and with them every rule that uses one:
     * the step renders with no styling at all, and nothing reports it. The
     * plugin owns the root of its own stylesheet, so the plugin provides it
     * and the custom view supplies the contents.
     *
     * The wrapper is not a styling courtesy and is not conditional on the
     * `css` property: a theme that brings its own stylesheet gets the same
     * element, and an unstyled wrapper is inert.
     */
    public function onRender(): ?string
    {
        $position = $this->currentPosition;

        if ($position === null || $this->config === null) {
            return null;
        }

        $view = $this->config->steps[$position]->view ?? null;

        if ($view === null) {
            return null;
        }

        return '<div class="wizard">' . $this->controller->renderPartial($view) . '</div>';
    }

    /**
     * Revalidates the data of the previous steps with the rules of their
     * original steps. Each step's extra validation runs with the accumulated
     * data of the EARLIER steps — the same context it had in its original
     * validation (the 1.x prevValidationsData parity) — so a cross-step
     * closure behaves identically on first validation and revalidation.
     * Success updates the derived data of those steps in the session;
     * failure returns the position of the first failing step (the caller
     * behaves like a "back" to it).
     */
    protected function revalidatePrevious(SessionStore $store, StepResolver $resolver, int $position): ?int
    {
        $validatorService = new StepValidator();
        $accumulated = [];

        for ($index = 0; $index < $position; $index++) {
            $previous = $resolver->step($index);

            if ($previous->rules === []) {
                // A step without rules still contributes its stored data
                // (session API writes) to the context of the later steps.
                $accumulated = array_merge($accumulated, $store->stepData($previous->code));

                continue;
            }

            try {
                $derived = $validatorService->validate($previous, $store->stepData($previous->code), $accumulated);
            } catch (\Winter\Storm\Exception\ValidationException) {
                return $index;
            }

            $accumulated = array_merge($accumulated, $store->stepData($previous->code), $derived);

            if ($derived !== []) {
                $store->saveStepData($previous->code, $derived);
            }
        }

        return null;
    }

    /**
     * Graceful bounce of a failed revalidation: behaves like a "back" to the
     * failing step — the data of the failing step and the later ones is
     * wiped (that is where the invalid or campaign-jump data lives), the
     * data of the previous steps is kept, and the user lands on the failing
     * step to re-fill it. When the FIRST step fails the whole state is
     * flushed (the same restart as an expired wizard).
     */
    protected function revalidateBounce(SessionStore $store, StepResolver $resolver, int $failing): RedirectResponse
    {
        if ($failing === 0) {
            // The first step is the failing one: the whole state is flushed
            // (the same restart as an expired wizard — a fresh session key,
            // nothing left behind).
            $store->flush();
        } else {
            $store->wipeFrom($resolver->step($failing - 1)->code);
            $store->setStepCurrent($failing);
        }

        return Redirect::to($resolver->urlFor($failing));
    }

    /**
     * Resolves the configuration: the declarative array built by the page
     * (levels 1 and 2) takes precedence over the component properties
     * (level 0). Both produce the same WizardConfig.
     *
     * The precedence is per SETTING, not wholesale: a wizard-wide setting the
     * page did not declare through the builder falls back to the component
     * property. Otherwise a property set on a page that also calls define()
     * would be read by nothing, and a privacy setting like `encrypted` would
     * look enabled while it is not.
     */
    protected function resolveConfig(): WizardConfig
    {
        return $this->config ??= $this->builderConfig !== null
            ? WizardConfig::fromArray($this->builderConfig + $this->wizardWideProperties())
            : WizardConfig::fromProperties($this->getProperties());
    }

    /**
     * The wizard-wide component properties, used as the fallback for the
     * settings the builder did not declare.
     */
    protected function wizardWideProperties(): array
    {
        $properties = $this->getProperties();
        $fallback = [];

        foreach (['finishUrl', 'ttl', 'wipeOnBack', 'retainData', 'encrypted', 'keepSteps'] as $key) {
            if (array_key_exists($key, $properties)) {
                $fallback[$key] = $properties[$key];
            }
        }

        return $fallback;
    }

    /**
     * The session key of this wizard instance.
     *
     * It is scoped by the page that declares the component and by the
     * component alias, so two wizards declared on different pages never read
     * or write each other's state, and two wizards on the same page are told
     * apart by their aliases. The page comes first because that is what
     * identifies the flow: a step is reached through the page URL, so a
     * wizard belongs to its page the way the 1.x key (`wizard_steps-<page>`)
     * belonged to it.
     */
    protected function sessionKey(): string
    {
        $scope = '';

        // $this->page is the controller, whose magic accessors forward to the
        // page being served; fileName is the page's own file name.
        $fileName = (string) ($this->page->fileName ?? '');
        $extension = strrpos($fileName, '.');

        if ($extension !== false) {
            $fileName = substr($fileName, 0, $extension);
        }

        if ($fileName !== '') {
            $scope = $fileName . '.';
        }

        return 'zimudec.wizard.' . $scope . $this->alias;
    }

    protected function makeStore(WizardConfig $config): SessionStore
    {
        return $this->store ??= new SessionStore(
            $this->sessionKey(),
            $config->encrypted,
            array_map(static fn ($step): string => $step->code, $config->steps)
        );
    }

    protected function makeResolver($config): StepResolver
    {
        return $this->resolver ??= new StepResolver($config, $this->page);
    }

    /**
     * Effective TTL in minutes: the wizard TTL overrides the global default;
     * null disables the expiration.
     */
    protected function effectiveTtl(WizardConfig $config): ?int
    {
        if ($config->ttl === 0) {
            return null;
        }

        return $config->ttl ?? config('zimudec.wizard::ttl', 30);
    }

    /**
     * The page must declare an optional step parameter in its URL
     * (e.g. "/:step?"); otherwise the wizard cannot work and the
     * configuration is rejected with a clear, translated error.
     */
    protected function ensureStepParamDeclared(): void
    {
        $url = $this->page->url ?? '';

        if (!str_contains($url, ':step?')) {
            throw new ConfigurationException(__('zimudec.wizard::lang.errors.step_param_missing'));
        }
    }

    /**
     * Exposes the wizard state to the view: the step list with its states,
     * the current step name, the navigation URLs and the accumulated data
     * (only user data; internal metadata never reaches the templates).
     */
    protected function exposeToView(
        WizardConfig $config,
        SessionStore $store,
        StepResolver $resolver,
        int $position
    ): void {
        $this->currentPosition = $position;

        $steps = [];
        foreach ($config->steps as $index => $step) {
            $steps[] = [
                'code' => $step->code,
                'title' => $step->title,
                'url' => $resolver->urlFor($index),
                'state' => match (true) {
                    $index < $position => 'completed',
                    $index === $position => 'current',
                    default => 'pending',
                },
            ];
        }

        $this->page['wizard'] = [
            'steps' => $steps,
            'stepCurrentName' => $resolver->step($position)->title,
            'stepNumber' => $position + 1,
            'urls' => [
                'next' => $resolver->nextUrl($position),
                'prev' => $resolver->prevUrl($position),
                'finish' => $config->finishUrl,
            ],
            'data' => $store->mergedData(),
            'retainData' => $config->retainData,
        ];
    }

    /**
     * Fields of the current step, for the step shell partials.
     */
    public function currentStepFields(): array
    {
        $position = $this->currentPosition ?? 0;

        return $this->config?->steps[$position]?->fields ?? [];
    }

    // ── Public session API (auxiliary handlers) ─────────────────────────
    // Read/write access to the wizard session zone for the page's own AJAX
    // handlers (live validation, carts, autosave) — the 1.x sessionGet()
    // and saveInSession() equivalents, without exposing the session key
    // and always through the same namespacing and encryption as the
    // submit pipeline.

    /**
     * Data of every step merged (user data plus the derived data of the
     * extra validations), decrypted.
     */
    public function data(): array
    {
        return $this->makeStore($this->resolveConfig())->mergedData();
    }

    /**
     * Data of every step, keyed by step code, decrypted.
     *
     * Nothing is merged here, so two steps may hold the same key name without
     * either hiding the other. This is what the last step of a flow needs to
     * finish an order, and it is the 1.x guarantee: there, the page read every
     * value from the one place it lived, so nothing could be overwritten.
     *
     * Merge it yourself when you want the flat view, and name your rules
     * explicitly — the plugin cannot tell an intentional override from two
     * steps meaning different things by the same name.
     */
    public function stepsData(): array
    {
        return $this->makeStore($this->resolveConfig())->data();
    }

    /**
     * Data of a single step, decrypted.
     *
     * data() merges the steps in config order, so a key stored by two steps
     * resolves to the later one. That is the right default for a form (the
     * later step is the one the user filled most recently) and the wrong tool
     * for a handler that wrote to a specific step: reading it back through
     * data() can return a different step's value for the same key, and the
     * write looks like it did nothing. This is the read half of saveData().
     *
     * @param string|null $stepCode the step to read; null reads the current one
     */
    public function stepData(?string $stepCode = null): array
    {
        $config = $this->resolveConfig();
        $store = $this->makeStore($config);
        $resolver = $this->makeResolver($config);

        $position = $stepCode !== null ? $resolver->position($stepCode) : $store->stepCurrent();

        if ($position === null) {
            throw new ConfigurationException(__('zimudec.wizard::lang.errors.step_not_found', ['step' => $stepCode]));
        }

        return $store->stepData($resolver->step($position)->code);
    }

    /**
     * Internal metadata of the wizard (current step, last activity,
     * finished flag).
     */
    public function meta(): array
    {
        return $this->makeStore($this->resolveConfig())->meta();
    }

    /**
     * Current step position (the last validated step index).
     */
    public function stepCurrent(): int
    {
        return $this->makeStore($this->resolveConfig())->stepCurrent();
    }

    /**
     * Persists data in the wizard session from outside the submit pipeline.
     * The values merge into the stored data of the given step (default: the
     * current one); existing values are preserved and the inactivity timer
     * is refreshed. Internal metadata is never touched.
     */
    public function saveData(array $data, ?string $stepCode = null): void
    {
        $config = $this->resolveConfig();
        $store = $this->makeStore($config);
        $resolver = $this->makeResolver($config);

        $position = $stepCode !== null ? $resolver->position($stepCode) : $store->stepCurrent();

        if ($position === null) {
            throw new ConfigurationException(__('zimudec.wizard::lang.errors.step_not_found', ['step' => $stepCode]));
        }

        $store->saveStepData($resolver->step($position)->code, $data);
        $store->touch();
    }

    /**
     * Removes the entire wizard state (only the namespaced zone of this
     * wizard instance).
     */
    public function flush(): void
    {
        $this->makeStore($this->resolveConfig())->flush();
    }

    /**
     * Removes named keys from the wizard session.
     *
     * saveData() merges: it preserves every key its argument does not name.
     * That is what a partial update should do, and it is also why a field that
     * changes name leaves the old key in the session for the lifetime of the
     * wizard — where data() can return it as a value no step wrote, with
     * nothing to tell it apart from a live one. This is the other half of the
     * write, and the only way to drop a key.
     *
     * Keys are top-level. Forgetting a key that was never stored is a no-op.
     * The progress pointer and the inactivity timer are not data and are
     * untouched. A step code the wizard does not declare is a configuration
     * mistake and fails fast, like every other step code in this API: quietly
     * forgetting nothing would look exactly like succeeding.
     *
     * @param array $keys the top-level keys to remove
     * @param string|null $stepCode the step to forget from; null forgets from every step,
     *                              which is the shape a rename usually needs — the code
     *                              renaming a field rarely knows every step that wrote it
     */
    public function forgetData(array $keys, ?string $stepCode = null): void
    {
        $config = $this->resolveConfig();
        $store = $this->makeStore($config);

        if ($stepCode !== null && $this->makeResolver($config)->position($stepCode) === null) {
            throw new ConfigurationException(__('zimudec.wizard::lang.errors.step_not_found', ['step' => $stepCode]));
        }

        $store->forgetStepKeys($stepCode, $keys);
    }

    /**
     * The guard for an auxiliary handler, fused with the read it protects.
     *
     * Returns the merged data when the named step is reachable and holds the
     * named keys, and a redirect to the furthest step the user has reached
     * when it does not. The two checks are the progress guard and the data
     * guard, which catch different mistakes and which the page otherwise has
     * to remember to write: a step can be out of reach, and it can be in
     * reach with its data gone (a wipe on back, or a jumpTo that unlocked the
     * step without prefilling it).
     *
     * Fusing the check with the read is the point. Write the check and the
     * read as two statements and there are two ways to get the second without
     * the first; here there is one way to get the data and it goes through the
     * check. Forgetting the guard stops being a line you did not write and
     * becomes a value you do not get. Ignoring the returned redirect is a
     * loud error, not a silent wrong answer — the next access reads the
     * response object as an array and fails.
     *
     * @param string $stepCode the step that produces the data
     * @param array $requiredKeys top-level keys that must be present and not empty
     * @return array|RedirectResponse the data, or where to send the user instead
     */
    public function requireData(string $stepCode, array $requiredKeys = []): array|RedirectResponse
    {
        $config = $this->resolveConfig();
        $store = $this->makeStore($config);
        $resolver = $this->makeResolver($config);

        $position = $resolver->position($stepCode);

        if ($position === null) {
            throw new ConfigurationException(__('zimudec.wizard::lang.errors.step_not_found', ['step' => $stepCode]));
        }

        $data = $store->mergedData();

        $reachable = $position <= $store->stepCurrent();

        foreach ($requiredKeys as $key) {
            $reachable = $reachable && !empty($data[$key]);
        }

        if (!$reachable) {
            return $this->redirectToStep($resolver, $store->stepCurrent());
        }

        return $data;
    }

    /**
     * Whether a value returned by requireData() has to be sent back to the
     * client as a redirect.
     *
     * The guard returns either the data or a RedirectResponse, and a handler
     * asks which one it got on every call. The question belongs to the wizard,
     * not to the page: the page would otherwise have to name the class the
     * guard happens to return, which ties it to a representation the plugin is
     * free to change, and a wrong class name fails silently — the guard stops
     * protecting and nothing reports it.
     *
     * It must also be safe for the data itself. Asking the value instead
     * ("$data->isRedirect()") reads better and is a fatal error on the success
     * path, where the value is an array, so every successful call would die.
     *
     * @param mixed $value the return value of requireData()
     */
    public function needsRedirect(mixed $value): bool
    {
        return $value instanceof RedirectResponse;
    }

    // ── Programmatic navigation ──────────────────────────────────────────
    // The 1.x sendToStep()/sendFirstStep() equivalents: render guards
    // (redirectTo) and deep-links with prefilled data (jumpTo), both
    // built on framework-native redirects (X_WINTER_REDIRECT for AJAX,
    // plain 302 otherwise).

    /**
     * Redirect to the URL of a step without changing the progress pointer.
     * Render guard of the page lifecycle: the wipe-on-back applies when
     * the user lands.
     */
    public function redirectTo(string $stepCode, array $query = []): RedirectResponse
    {
        return Redirect::to($this->stepUrl($stepCode, $query));
    }

    /**
     * Deep-link API: persists the provided data (keyed by step code),
     * unlocks the flow up to the target step and returns the redirect to
     * it. Fails fast when a step code does not exist.
     */
    public function jumpTo(string $stepCode, array $data = [], array $query = []): RedirectResponse
    {
        $config = $this->resolveConfig();
        $store = $this->makeStore($config);
        $resolver = $this->makeResolver($config);

        $position = $resolver->position($stepCode);

        if ($position === null) {
            throw new ConfigurationException(__('zimudec.wizard::lang.errors.step_not_found', ['step' => $stepCode]));
        }

        foreach ($data as $code => $values) {
            $dataPosition = $resolver->position((string) $code);

            if ($dataPosition === null) {
                throw new ConfigurationException(__('zimudec.wizard::lang.errors.step_not_found', ['step' => $code]));
            }

            $store->saveStepData($resolver->step($dataPosition)->code, (array) $values);
        }

        $store->setStepCurrent($position);
        $store->touch();

        return Redirect::to($this->appendQuery($resolver->urlFor($position), $query));
    }

    /**
     * URL of a configured step, with optional query parameters. Fails fast
     * when the step code does not exist.
     */
    protected function stepUrl(string $stepCode, array $query = []): string
    {
        $resolver = $this->makeResolver($this->resolveConfig());
        $position = $resolver->position($stepCode);

        if ($position === null) {
            throw new ConfigurationException(__('zimudec.wizard::lang.errors.step_not_found', ['step' => $stepCode]));
        }

        return $this->appendQuery($resolver->urlFor($position), $query);
    }

    protected function appendQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }
}
