<?php

namespace Zimudec\Wizard\Tests\Acceptance;

use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * Render tests of the step shell and the accessible step indicator
 * (F5): aria-current="step" on the current step, completed/current/pending
 * states, Snowboard validation hooks on the form and CSRF protection.
 */
class ShellRenderTest extends BaseTestCase
{
    public function test_step_indicator_marks_the_current_step_with_aria_current()
    {
        $content = $this->get('/level-0/step-1')->assertStatus(200)->getContent();

        $this->assertMatchesRegularExpression('/wizard__nav-item--current[^>]*aria-current="step"/s', $content);
        $this->assertStringContainsString('wizard__nav-item--pending', $content);
        $this->assertStringContainsString('Step 1', $content);
        $this->assertStringContainsString('Step 2', $content);
    }

    public function test_form_carries_the_snowboard_hooks()
    {
        $content = $this->get('/level-0/step-1')->assertStatus(200)->getContent();

        // Single generic handler with the component alias.
        $this->assertStringContainsString('data-request="wizard::onNext"', $content);
        // Server-side validation for Snowboard (inline errors).
        $this->assertStringContainsString('data-request-validate', $content);
        // CSRF token of the framework.
        $this->assertMatchesRegularExpression('/<input name="_token" type="hidden" value=".+">/', $content);
        // No-JS postback dispatch (progressive enhancement).
        $this->assertStringContainsString('<input type="hidden" name="_handler" value="wizard::onNext">', $content);
        $this->assertStringContainsString('data-attach-loading', $content);
    }

    public function test_the_current_step_name_is_available_for_the_page_title()
    {
        session([$this->wizardSessionKey('level-0') => ['data' => [], 'meta' => ['stepCurrent' => 1]]]);

        $content = $this->get('/level-0/step-2')->assertStatus(200)->getContent();

        // The wizard exposes the current step name to the page.
        $this->assertStringContainsString('Step 2', $content);
    }

    public function test_partials_render_translated_strings_per_locale()
    {
        $this->app->setLocale('es');
        $sessionKey = $this->wizardSessionKey('level-0');

        session([$sessionKey => ['data' => [], 'meta' => ['stepCurrent' => 1]]]);

        $content = $this->get('/level-0/step-1')->assertStatus(200)->getContent();

        $this->assertStringContainsString('aria-label="Pasos"', $content);
        $this->assertStringContainsString('Siguiente', $content);

        $this->app->setLocale('fr');
        session([$sessionKey => ['data' => [], 'meta' => ['stepCurrent' => 0]]]);

        $content = $this->get('/level-0/step-1')->assertStatus(200)->getContent();

        $this->assertStringContainsString('aria-label="Étapes"', $content);
        $this->assertStringContainsString('Suivant', $content);

        $this->app->setLocale('en');
    }

    public function test_the_error_bag_is_programmatically_associated_with_the_control()
    {
        $content = $this->get('/level-1/step-1')->assertStatus(200)->getContent();

        // ARIA1: the control references its error bag; ARIA19: the bag is a
        // live region announced when Snowboard fills it.
        $this->assertMatchesRegularExpression('/id="field1"\s+name="field1"\s+value=""\s+aria-describedby="field1-error"/', $content);
        $this->assertMatchesRegularExpression('/id="field1-error" role="alert" data-validate-error="field1"/', $content);
    }

    public function test_the_current_step_title_is_rendered_in_the_shell()
    {
        $content = $this->get('/level-0/step-1')->assertStatus(200)->getContent();

        $this->assertMatchesRegularExpression('/<h2 class="wizard__title">Step 1<\/h2>/', $content);
    }

    public function test_the_default_stylesheet_is_served_through_the_asset_combiner()
    {
        $content = $this->get('/level-0/step-1')->assertStatus(200)->getContent();

        $this->assertMatchesRegularExpression('/href="[^"]*\/combine\/[^"]*"/', $content);
    }

    public function test_the_focus_script_is_served_through_the_asset_combiner()
    {
        $content = $this->get('/level-0/step-1')->assertStatus(200)->getContent();

        // The script is not parser-blocking: it loads in parallel and runs
        // after parsing (defer executes before DOMContentLoaded, in order).
        $this->assertMatchesRegularExpression('/src="[^"]*\/combine\/[^"]*"[^>]*defer="defer"/', $content);
    }

    /**
     * The step HTML repopulates the values the user already entered, so the
     * response carries PII. It must not be retained in the browser cache nor
     * recoverable with the Back button (OWASP WSTG, browser cache weaknesses;
     * OWASP HTTP Headers Cheat Sheet: no-store for sensitive data).
     */
    public function test_the_step_response_sends_no_store()
    {
        $response = $this->get('/level-0/step-1')->assertStatus(200);

        // Symfony appends "private" whenever no s-maxage or public directive
        // is set, so the guarantee is the presence of no-store among the
        // directives, not the exact header value.
        $directives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));

        $this->assertContains('no-store', $directives);
    }

    /**
     * The submit response carries no user data (the redirect is empty, the
     * 406 payload holds the messages the developer defined), so it does not
     * need the directive. Setting it there would evict the page from the
     * back/forward cache on every submission, for nothing
     * (Chrome bfcache no-store: an XHR that also answers no-store evicts the
     * page).
     */
    public function test_the_submit_response_does_not_send_no_store()
    {
        $response = $this->ajaxRequest('/level-0/step-1', 'wizard::onNext', ['field1' => 'value']);

        $directives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));

        $this->assertNotContains('no-store', $directives);
    }

    public function test_the_stylesheet_can_be_turned_off_from_the_component_properties()
    {
        $content = $this->get('/level-nocss/step-1')->assertStatus(200)->getContent();

        $this->assertDoesNotMatchRegularExpression('/<link rel="stylesheet" href="[^"]*\/combine\//', $content);
        // The wizard still renders (the theme provides its own styling).
        $this->assertMatchesRegularExpression('/wizard__nav-item--current[^>]*aria-current="step"/s', $content);
    }

    public function test_the_focus_script_can_be_turned_off_from_the_component_properties()
    {
        $content = $this->get('/level-nojs/step-1')->assertStatus(200)->getContent();

        $this->assertDoesNotMatchRegularExpression('/<script[^>]*src="[^"]*\/combine\//', $content);
        // The stylesheet is unaffected by the js property.
        $this->assertMatchesRegularExpression('/href="[^"]*\/combine\/[^"]*"/', $content);
        // The wizard still renders (the theme implements its own behaviour).
        $this->assertMatchesRegularExpression('/wizard__nav-item--current[^>]*aria-current="step"/s', $content);
    }
}
