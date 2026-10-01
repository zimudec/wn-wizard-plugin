<?php

namespace Zimudec\Wizard\Tests\Acceptance;

use Event;
use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * Level 2 (full control): per-step extra validation, per-step custom view
 * and per-step handler, plus the wizard cycle events (beforeValidate,
 * afterCommit, stepCompleted) that other plugins can listen to and affect
 * the flow with.
 */
class LevelTwoTest extends BaseTestCase
{
    public function test_extra_validation_blocks_the_advance_with_field_errors()
    {
        $response = $this->ajaxRequest('/level-2/step-1', 'wizard::onNext', ['field1' => 'forbidden']);

        $this->assertAjaxException($response, [
            'field1' => ['Extra validation failed'],
        ]);
    }

    public function test_handler_runs_once_per_successful_submit()
    {
        session(['level2_handler_ran' => false]);

        $this->ajaxRequest('/level-2/step-1', 'wizard::onNext', ['field1' => 'value'])
            ->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/level-2/step-2'));

        $this->assertTrue(session('level2_handler_ran'));
    }

    public function test_a_step_can_declare_its_own_view()
    {
        session([$this->wizardSessionKey('level-2') => [
            'data' => ['step-1' => ['field1' => 'from-session']],
            'meta' => ['stepCurrent' => 1],
        ]]);

        $content = $this->get('/level-2/step-2')->assertStatus(200)->getContent();

        // The page partial renders for the step instead of the default shell.
        $this->assertStringContainsString('custom-step-view', $content);
        $this->assertStringContainsString('from-session', $content);
        $this->assertStringNotContainsString('wizard__form', $content);
    }

    /**
     * The step's own view is wrapped in the element that carries the design
     * tokens.
     *
     * Every rule of the stylesheet consumes a `var(--wizard-*)`, and the
     * tokens are declared on `.wizard` itself. A custom view that omits that
     * wrapper therefore loses the tokens, and with them every rule that uses
     * one — the step renders with no styling at all and nothing reports it.
     * The plugin owns the root of its own stylesheet, so the plugin provides
     * it and the custom view supplies the contents.
     */
    public function test_a_step_view_renders_inside_the_element_that_carries_the_design_tokens()
    {
        session([$this->wizardSessionKey('level-2') => [
            'data' => ['step-1' => ['field1' => 'from-session']],
            'meta' => ['stepCurrent' => 1],
        ]]);

        $content = $this->get('/level-2/step-2')->assertStatus(200)->getContent();

        // The wrapper is the root of the component output, and it wraps the
        // view rather than being part of it.
        $this->assertMatchesRegularExpression(
            '/<div class="wizard">\s*<div class="custom-step-view">/',
            $content
        );
    }

    /**
     * The wrapper does not disappear when the plugin's stylesheet is off: a
     * theme that styles the markup itself with `css = 0` still gets the
     * element, and an unstyled wrapper is inert. A guard that demanded the
     * wrapper only while the stylesheet is on would forbid that configuration.
     */
    public function test_a_step_view_keeps_the_wrapper_with_the_stylesheet_disabled()
    {
        session([$this->wizardSessionKey('level-2-nocss') => [
            'data' => ['step-1' => ['field1' => 'from-session']],
            'meta' => ['stepCurrent' => 1],
        ]]);

        $content = $this->get('/level-2-nocss/step-2')->assertStatus(200)->getContent();

        $this->assertStringContainsString('class="wizard"', $content);
        $this->assertStringContainsString('custom-step-view', $content);
    }
}
