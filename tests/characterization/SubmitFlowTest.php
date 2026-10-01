<?php

namespace Zimudec\Wizard\Tests\Characterization;

use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * Regression tests of the submit flow of 2.0: a single generic handler
 * (wizard::onNext) driven by the step in the URL, redirect to the next step
 * and the namespaced session shape. The 1.0.7 golden master of this flow
 * (per-page handlers with formsValidate(), flat session, raw array JSON
 * response) was recorded during the 2.0 rework and replaced here.
 */
class SubmitFlowTest extends BaseTestCase
{
    /**
     * Server-side gating applies to AJAX submits too: a step not yet
     * reachable cannot be submitted; the request is redirected to the last
     * valid step.
     */
    public function test_submit_to_a_future_step_redirects_to_the_last_valid_step()
    {
        $this->ajaxRequest('/level-0/step-2', 'wizard::onNext')
            ->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/level-0/step-1'));

        // The gated request did not persist any state.
        $this->assertNull(session($this->wizardSessionKey('level-0')));
    }

    /**
     * @2.0-migration The 1.0.7 submit response was a plain array
     * {"stepNext": ..., "return": ...} with the flat session; 2.0 returns a
     * RedirectResponse that the framework converts into X_WINTER_REDIRECT
     * and stores the state under zimudec.wizard.<page>.<alias> {data,meta}.
     */
    public function test_submit_without_fields_redirects_to_next_step_and_advances()
    {
        $response = $this->ajaxRequest('/level-0/step-1', 'wizard::onNext');

        $response->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/level-0/step-2'));

        $state = session($this->wizardSessionKey('level-0'));
        $this->assertEquals(1, $state['meta']['stepCurrent']);
        $this->assertArrayHasKey('lastActivity', $state['meta']);
        $this->assertEquals([], $state['data']);
    }

    public function test_submit_on_last_step_clears_state_and_lands_on_finish_url()
    {
        session([$this->wizardSessionKey('level-0') => ['data' => [], 'meta' => ['stepCurrent' => 1]]]);

        $this->ajaxRequest('/level-0/step-2', 'wizard::onNext')->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/'));

        $this->assertNull(session($this->wizardSessionKey('level-0')));
    }
}
