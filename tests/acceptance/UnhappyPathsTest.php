<?php

namespace Zimudec\Wizard\Tests\Acceptance;

use Carbon\Carbon;
use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * Final e2e of the unhappy paths (F6): expired TTL (GET and AJAX), failed
 * revalidation, no-JS POST (progressive enhancement) and the derived data
 * updated by the revalidation.
 */
class UnhappyPathsTest extends BaseTestCase
{
    public function test_an_expired_wizard_restarts_on_get()
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));

        session([$this->wizardSessionKey('level-0') => [
            'data' => ['step-1' => ['field1' => 'value']],
            'meta' => ['stepCurrent' => 1, 'lastActivity' => Carbon::parse('2026-01-01 11:29:00')->getTimestamp()],
        ]]);

        Carbon::setTestNow(Carbon::parse('2026-01-01 12:30:00'));

        $this->get('/level-0/step-2')->assertStatus(302)->assertRedirect('/level-0/step-1');

        // The expired state was flushed.
        $this->assertNull(session($this->wizardSessionKey('level-0')));

        Carbon::setTestNow();
    }

    public function test_an_expired_wizard_restarts_on_ajax_submit()
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));

        session([$this->wizardSessionKey('level-0') => [
            'data' => ['step-1' => ['field1' => 'value']],
            'meta' => ['stepCurrent' => 1, 'lastActivity' => Carbon::parse('2026-01-01 11:29:00')->getTimestamp()],
        ]]);

        Carbon::setTestNow(Carbon::parse('2026-01-01 12:30:00'));

        $this->ajaxRequest('/level-0/step-2', 'wizard::onNext')
            ->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/level-0/step-1'));

        $this->assertNull(session($this->wizardSessionKey('level-0')));

        Carbon::setTestNow();
    }

    public function test_a_failed_revalidation_restarts_the_wizard()
    {
        // The data of the first step violates its own rules (the value was
        // mutated elsewhere or the rules changed).
        session([$this->wizardSessionKey('level-revalidate') => [
            'data' => ['step-1' => ['field1' => null, 'derived' => 'x']],
            'meta' => ['stepCurrent' => 1],
        ]]);

        $this->ajaxRequest('/level-revalidate/step-2', 'wizard::onNext', ['field2' => 'value'])
            ->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/level-revalidate/step-1'));

        // The session was flushed and the flow restarts.
        $this->assertNull(session($this->wizardSessionKey('level-revalidate')));
    }

    public function test_the_revalidate_gate_bounces_to_the_first_incomplete_step_on_entry()
    {
        // The pointer allows step-3 (a deep-link jumpTo unlocked it) and the
        // data of step-1 is valid, but the data of step-2 violates its rules:
        // the entry gate behaves like a "back" to the failing step — the data
        // of the failing step and the later ones is wiped (that is where the
        // invalid or campaign-junk data lives), the data of the previous steps
        // is kept, and the user lands on the failing step to re-fill it.
        // The bounce drops the entry query on purpose: re-running the same
        // invalid prefill would loop the user.
        session([$this->wizardSessionKey('level-revalidate-mid') => [
            'data' => ['step-1' => ['field1' => 'value', 'derived' => 'value-derived'], 'step-2' => ['field2' => null]],
            'meta' => ['stepCurrent' => 2],
        ]]);

        $this->get('/level-revalidate-mid/step-3?campaign=1')
            ->assertStatus(302)
            ->assertRedirect('/level-revalidate-mid/step-2');

        $state = session($this->wizardSessionKey('level-revalidate-mid'));

        // The data of the previous steps is kept; the failing step is wiped.
        $this->assertSame('value', $state['data']['step-1']['field1']);
        $this->assertSame('value-derived', $state['data']['step-1']['derived']);
        $this->assertArrayNotHasKey('step-2', $state['data']);

        // The pointer allows re-entering the failing step.
        $this->assertSame(1, $state['meta']['stepCurrent']);
    }

    public function test_a_failed_revalidation_on_submit_bounces_to_the_first_incomplete_step()
    {
        // The same graceful bounce on the submit path: submitting step-3 with
        // a valid current field and a broken step-2 lands the user on step-2
        // with the previous data intact — nothing is lost but the broken data.
        session([$this->wizardSessionKey('level-revalidate-mid') => [
            'data' => ['step-1' => ['field1' => 'value', 'derived' => 'value-derived'], 'step-2' => ['field2' => null]],
            'meta' => ['stepCurrent' => 2],
        ]]);

        $this->ajaxRequest('/level-revalidate-mid/step-3', 'wizard::onNext', ['field3' => 'value'])
            ->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/level-revalidate-mid/step-2'));

        $state = session($this->wizardSessionKey('level-revalidate-mid'));

        $this->assertSame('value', $state['data']['step-1']['field1']);
        $this->assertSame('value-derived', $state['data']['step-1']['derived']);
        $this->assertArrayNotHasKey('step-2', $state['data']);
        $this->assertSame(1, $state['meta']['stepCurrent']);
    }

    public function test_the_revalidate_on_submit_gate_skips_the_entry_check()
    {
        // revalidateOnSubmit() declares the revalidation for the submit only:
        // an entry with broken previous data is NOT blocked (the visits do not
        // pay the revalidation cost) and the submit catches it with the same
        // graceful bounce.
        session([$this->wizardSessionKey('level-revalidate-onsubmit') => [
            'data' => ['step-1' => ['field1' => 'value', 'derived' => 'value-derived'], 'step-2' => ['field2' => null]],
            'meta' => ['stepCurrent' => 2],
        ]]);

        $this->get('/level-revalidate-onsubmit/step-3')->assertStatus(200);

        $this->ajaxRequest('/level-revalidate-onsubmit/step-3', 'wizard::onNext', ['field3' => 'value'])
            ->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/level-revalidate-onsubmit/step-2'));

        $state = session($this->wizardSessionKey('level-revalidate-onsubmit'));

        $this->assertSame('value', $state['data']['step-1']['field1']);
        $this->assertArrayNotHasKey('step-2', $state['data']);
        $this->assertSame(1, $state['meta']['stepCurrent']);
    }

    public function test_the_revalidate_on_submit_gate_passes_and_completes_when_the_previous_data_is_valid()
    {
        // The normal path of a revalidateOnSubmit flow: a valid previous data
        // does not block the entry (same as no gate) and the submit revalidates
        // it, so the flow completes as usual.
        session([$this->wizardSessionKey('level-revalidate-onsubmit') => [
            'data' => ['step-1' => ['field1' => 'value', 'derived' => 'old'], 'step-2' => ['field2' => 'x']],
            'meta' => ['stepCurrent' => 2],
        ]]);

        $this->ajaxRequest('/level-revalidate-onsubmit/step-3', 'wizard::onNext', ['field3' => 'value'])
            ->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/'));

        // Completing the last step clears the state (no keepData on step 3).
        $this->assertNull(session($this->wizardSessionKey('level-revalidate-onsubmit')));
    }

    public function test_the_submit_shows_the_current_field_errors_before_the_revalidation()
    {
        // The three-phase order: the base rules of the current step run FIRST
        // (cheap), so a current field error reaches the user as a field error
        // instead of the revalidation bounce — and the revalidation closures
        // of the previous steps never run.
        session([$this->wizardSessionKey('level-revalidate') => [
            'data' => ['step-1' => ['field1' => null, 'derived' => 'x']],
            'meta' => ['stepCurrent' => 1],
        ]]);

        $this->assertAjaxException(
            $this->ajaxRequest('/level-revalidate/step-2', 'wizard::onNext', ['field2' => '']),
            ['field2' => ['The field2 field is required.']]
        );

        // The session is intact: no bounce destroyed the state.
        $state = session($this->wizardSessionKey('level-revalidate'));
        $this->assertSame(1, $state['meta']['stepCurrent']);
    }

    public function test_the_revalidate_gate_blocks_the_entry_to_the_step()
    {
        // The progress pointer allows step-2 (a deep-link jumpTo unlocked
        // it) but the data of step-1 violates its rules: the gate runs on
        // entry, so the user never fills a form that cannot be advanced.
        // The bounce drops the entry query on purpose: re-running the
        // same invalid prefill would loop the user.
        session([$this->wizardSessionKey('level-revalidate') => [
            'data' => ['step-1' => ['field1' => null, 'derived' => 'x']],
            'meta' => ['stepCurrent' => 1],
        ]]);

        $this->get('/level-revalidate/step-2?campaign=1')
            ->assertStatus(302)
            ->assertRedirect('/level-revalidate/step-1');

        // The session was flushed and the flow restarts.
        $this->assertNull(session($this->wizardSessionKey('level-revalidate')));
    }

    public function test_the_revalidate_gate_passes_when_the_previous_data_is_valid()
    {
        // A valid previous step does not block the entry (the normal path
        // of every flow that reached the step through the pipeline).
        session([$this->wizardSessionKey('level-revalidate') => [
            'data' => ['step-1' => ['field1' => 'value', 'derived' => 'value-derived']],
            'meta' => ['stepCurrent' => 1],
        ]]);

        $this->get('/level-revalidate/step-2')->assertStatus(200);
    }

    public function test_a_successful_revalidation_updates_the_derived_data()
    {
        $this->ajaxRequest('/level-revalidate/step-1', 'wizard::onNext', ['field1' => 'one'])
            ->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/level-revalidate/step-2'));

        $state = session($this->wizardSessionKey('level-revalidate'));
        $this->assertEquals('one-derived', $state['data']['step-1']['derived']);

        $this->ajaxRequest('/level-revalidate/step-2', 'wizard::onNext', ['field2' => 'value'])
            ->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/'));

        // The revalidation recomputed the derived data of the first step.
        $this->assertNull(session($this->wizardSessionKey('level-revalidate')));
    }

    public function test_the_bounce_wipes_the_failing_step_even_when_the_previous_step_stored_no_data()
    {
        // A middle step WITHOUT rules never stores data (its whitelist is
        // empty), so the bounce boundary cannot come from the stored keys:
        // it must come from the config order of the steps — otherwise the
        // graceful bounce silently keeps the invalid data of the failing
        // step (privacy: the wipe never reached it).
        session([$this->wizardSessionKey('level-revalidate-norules') => [
            'data' => [
                'step-1' => ['field1' => 'value', 'derived' => 'value-derived'],
                'step-3' => ['field3' => null],
            ],
            'meta' => ['stepCurrent' => 3],
        ]]);

        $this->get('/level-revalidate-norules/step-4')
            ->assertStatus(302)
            ->assertRedirect('/level-revalidate-norules/step-3');

        $state = session($this->wizardSessionKey('level-revalidate-norules'));

        $this->assertSame('value', $state['data']['step-1']['field1']);
        $this->assertSame('value-derived', $state['data']['step-1']['derived']);
        $this->assertArrayNotHasKey('step-3', $state['data']);
        $this->assertSame(2, $state['meta']['stepCurrent']);
    }

    public function test_the_revalidation_runs_the_extra_validation_with_the_accumulated_data_of_the_earlier_steps()
    {
        // The 1.x prevValidationsData parity: the extra validation of a
        // previous step reads the data of the EARLIER steps during the
        // revalidation, the same accumulated context it had in its original
        // validation — otherwise a cross-step closure fails the
        // revalidation with data "missing" that actually exists, and the
        // user is bounced out of a valid flow.
        session([$this->wizardSessionKey('level-revalidate-cross') => [
            'data' => [
                'step-1' => ['field1' => 'value', 'derived' => 'value-derived'],
                'step-2' => ['field2' => 'x', 'stamp' => 'stale'],
            ],
            'meta' => ['stepCurrent' => 2],
        ]]);

        $this->get('/level-revalidate-cross/step-3')->assertStatus(200);

        $state = session($this->wizardSessionKey('level-revalidate-cross'));

        // The cross-step closure of step-2 recomputed its stamp from the
        // data of step-1 during the revalidation.
        $this->assertSame('value-stamped', $state['data']['step-2']['stamp']);
    }

    public function test_a_post_without_javascript_receives_an_http_redirect()
    {
        // A plain POST (no AJAX headers) with the framework postback field.
        $response = $this->post('/level-0/step-1', [
            '_handler' => 'wizard::onNext',
            'field1' => 'value',
            'unknown' => 'discarded',
        ]);

        $response->assertStatus(302)->assertRedirect('/level-0/step-2');
    }
}
