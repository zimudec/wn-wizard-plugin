/*
 * Focus management, form-level errors and double-submission guard for the
 * wizard forms: when an AJAX submission fails server-side validation, the
 * invalid fields are marked with aria-invalid and focus moves to the first
 * one (WAI-ARIA APG form errors pattern, WCAG 3.3.1 / 2.4.3). Business-rule
 * errors without a field in the DOM (cross-field checks like "select at
 * least one") have no field bag for Snowboard's FormValidation to paint
 * into, so this script renders them into the form-level error container
 * (data-wizard-errors) — a minimal GOV.UK-style error summary. Keys with
 * the underscore prefix are client control channels and are never painted;
 * the same goes for non-string messages.
 *
 * While an AJAX submission is in flight, further submit events on the same
 * form are cancelled in the capture phase — before Snowboard's delegated
 * window-level submit handler can start another request — so a double
 * click (or an Enter) during the request cannot reach the server twice.
 * The web standard does not prevent double submissions; developers must
 * opt in from JavaScript (WHATWG HTML #5312). The guard never disables
 * the button: disabled controls are not announced by assistive
 * technologies and do not say why they cannot be used (GOV.UK / UK
 * Parliament design systems). The form carries aria-busy while the
 * request is in flight, and the visual feedback is left to the theme
 * (data-attach-loading). The lock releases on ajaxDone, ajaxFail and
 * ajaxAlways, so every exit path frees the form: a cancelled request
 * fires only ajaxAlways, and a release that never comes would leave the
 * form permanently unable to submit. A redirect response leaves the
 * page, so the lock dies with it.
 *
 * The logic listens to the DOM events that Snowboard's request layer
 * dispatches on the form (ajaxPromise / ajaxDone / ajaxFail) instead of
 * registering a Snowboard plugin, so it works regardless of how the
 * Snowboard singleton is initialised by the theme.
 */
(function () {
    'use strict';

    const paintErrors = (event) => {
        const request = event.request || {};
        const form = request.element instanceof HTMLFormElement ? request.element : null;
        const response = request.responseData || request.responseError || {};
        const fields = response.X_WINTER_ERROR_FIELDS;
        if (!fields || !form) {
            return;
        }

        // Defer to the next frame: Snowboard's FormValidation extra paints
        // the error bags from this same event.
        window.requestAnimationFrame(() => {
            const names = Object.keys(fields);
            let domFields = 0;

            names.forEach((name) => {
                const field = form.querySelector('#' + CSS.escape(name));
                if (field) {
                    field.setAttribute('aria-invalid', 'true');
                    domFields += 1;
                }
            });

            // Orphan (DOM-less) field errors go into the form-level
            // container.
            const container = form.querySelector('[data-wizard-errors]');
            if (container) {
                const list = container.querySelector('[data-wizard-errors-list]');
                const orphans = [];

                names.forEach((name) => {
                    if (name.charAt(0) === '_') {
                        return;
                    }

                    if (form.querySelector('[data-validate-error="' + CSS.escape(name) + '"]')) {
                        return;
                    }

                    [].concat(fields[name] || []).forEach((message) => {
                        if (typeof message === 'string') {
                            orphans.push(message);
                        }
                    });
                });

                if (list) {
                    list.innerHTML = '';
                    orphans.forEach((message) => {
                        const item = document.createElement('p');
                        item.textContent = message;
                        list.appendChild(item);
                    });
                }

                container.hidden = orphans.length === 0;

                if (domFields === 0 && orphans.length > 0) {
                    container.focus();
                }
            }

            const first = names.map((name) => form.querySelector('#' + CSS.escape(name))).find(Boolean);
            if (first && typeof first.focus === 'function') {
                first.focus();
            }
        });
    };

    const clearForm = (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        if (!form) {
            return;
        }

        form.querySelectorAll('[aria-invalid]').forEach((element) => {
            element.removeAttribute('aria-invalid');
        });

        const container = form.querySelector('[data-wizard-errors]');
        if (container) {
            container.hidden = true;
            const list = container.querySelector('[data-wizard-errors-list]');
            if (list) {
                list.innerHTML = '';
            }
        }
    };

    const lock = (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        if (form) {
            form.dataset.wizardSubmitting = '1';
            form.setAttribute('aria-busy', 'true');
        }
    };

    const release = (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        if (form) {
            delete form.dataset.wizardSubmitting;
            form.removeAttribute('aria-busy');
        }
    };

    const attach = (form) => {
        if (form.dataset.wizardFocusAttached) {
            return;
        }

        form.dataset.wizardFocusAttached = '1';
        form.addEventListener('ajaxPromise', clearForm);
        form.addEventListener('ajaxPromise', lock);
        form.addEventListener('ajaxDone', release);
        form.addEventListener('ajaxFail', paintErrors);
        form.addEventListener('ajaxFail', release);
        // A cancelled request is the one path that dispatches neither
        // ajaxDone nor ajaxFail: Snowboard calls complete() directly, which
        // only fires ajaxAlways on the element. Releasing here covers it, and
        // releasing twice is harmless.
        form.addEventListener('ajaxAlways', release);
    };

    const attachAll = () => {
        document.querySelectorAll('form[data-request-validate]').forEach(attach);
    };

    // Capture phase: the delegated submit listener of Snowboard's
    // AttributeRequest registers on window in the bubble phase, so a
    // document-level capture listener always runs first and can cancel a
    // second submission before any request is created.
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (form instanceof HTMLFormElement && form.dataset.wizardSubmitting === '1') {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachAll, { once: true });
    } else {
        attachAll();
    }
})();
