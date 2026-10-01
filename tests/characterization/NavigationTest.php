<?php

namespace Zimudec\Wizard\Tests\Characterization;

use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * Regression tests of the navigation behavior that 2.0 preserves from
 * 1.0.7: step resolution by URL parameter, server-side gating of not-yet-
 * reachable steps and restart of the flow. These were the golden-master
 * tests of 1.0.7; after the rework they pin the preserved semantics
 * (framework-native redirects replace the send()/exit() seam, and the
 * session uses the namespaced zimudec.wizard.<page>.<alias> {data,meta} shape —
 * see the @2.0-migration comments recorded during the characterization).
 */
class NavigationTest extends BaseTestCase
{
    public function test_get_without_step_redirects_to_first_step()
    {
        $this->get('/level-0')->assertStatus(302)->assertRedirect('/level-0/step-1');
    }

    public function test_get_future_step_with_empty_session_redirects_to_first_step()
    {
        $this->get('/level-0/step-2')->assertStatus(302)->assertRedirect('/level-0/step-1');
    }

    public function test_get_unknown_step_with_empty_session_redirects_to_first_step()
    {
        $this->get('/level-0/isnt-a-step')->assertStatus(302)->assertRedirect('/level-0/step-1');
    }

    /**
     * @2.0-migration In 1.0.7 the session was seeded flat as
     * wizard_steps-<page> => ['stepCurrent' => n]; in 2.0 the state lives in
     * the namespaced zimudec.wizard.<page>.<alias> key with the {data,meta} shape.
     */
    public function test_get_unknown_step_with_session_redirects_to_last_valid_step()
    {
        session([$this->wizardSessionKey('level-0') => ['data' => [], 'meta' => ['stepCurrent' => 1]]]);

        $this->get('/level-0/isnt-a-step')->assertStatus(302)->assertRedirect('/level-0/step-2');
    }

    public function test_get_allowed_step_renders_200()
    {
        session([$this->wizardSessionKey('level-0') => ['data' => [], 'meta' => ['stepCurrent' => 1]]]);

        $this->get('/level-0/step-2')
            ->assertStatus(200)
            ->assertSee('Step 2', false);
    }

    /**
     * @2.0-migration In 1.0.7 the session was cleared BEFORE rendering the
     * last step (bug B4) so the final view had no data. In 2.0 the state is
     * cleared when the last step is COMPLETED (its submit), not on render:
     * rendering the last step keeps the data (and going back keeps the flow
     * editable); once the state is cleared, a reload of the last step
     * redirects to the first step.
     */
    public function test_last_step_render_keeps_data_until_the_next_interaction()
    {
        session([$this->wizardSessionKey('level-0') => [
            'data' => ['step-1' => ['field1' => 'value']],
            'meta' => ['stepCurrent' => 1],
        ]]);

        // The last step renders with its data, and re-rendering keeps it.
        $this->get('/level-0/step-2')->assertStatus(200);
        $this->get('/level-0/step-2')->assertStatus(200);
    }

    /**
     * @2.0-migration In 1.0.7 re-entering step 1 wiped the flat session but
     * left a fresh stepCurrent marker behind (stepSessionClear ran after the
     * wipe); in 2.0 going back wipes the data of the steps AFTER the current
     * one (privacy default) while the data of the current step is retained
     * (the selection of the fields is restored on return — bug B1 fix).
     */
    public function test_going_back_wipes_only_the_data_of_later_steps()
    {
        session([$this->wizardSessionKey('level-1') => [
            'data' => [
                'step-1' => ['field1' => 'keep-me'],
                'step-2' => ['field3' => 'wipe-me'],
            ],
            'meta' => ['stepCurrent' => 2],
        ]]);

        $this->get('/level-1/step-1')->assertStatus(200);

        $state = session($this->wizardSessionKey('level-1'));
        $this->assertEquals(['step-1' => ['field1' => 'keep-me']], $state['data']);
    }
}
