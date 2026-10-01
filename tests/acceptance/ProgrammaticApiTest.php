<?php

namespace Zimudec\Wizard\Tests\Acceptance;

use Cms\Classes\Theme;
use Zimudec\Wizard\Support\ConfigurationException;
use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * Programmatic control from the page code: the session API for auxiliary
 * handlers (data/meta/stepCurrent/saveData/flush) and the programmatic
 * navigation (redirectTo/jumpTo and a handler that returns a redirect).
 */
class ProgrammaticApiTest extends BaseTestCase
{
    public function test_an_auxiliary_handler_can_read_and_write_the_wizard_session()
    {
        $response = $this->ajaxRequest('/level-api/step-1', 'onCartData', ['cart_total' => 99]);

        $response->assertOk();

        $payload = $response->json();
        $this->assertEquals(99, $payload['data']['cart_total']);
        $this->assertEquals(0, $payload['stepCurrent']);
        $this->assertArrayHasKey('lastActivity', $payload['meta']);
        $this->assertArrayNotHasKey('validations', $payload['data']); // old flat shape is gone
    }

    public function test_auxiliary_writes_reach_the_views()
    {
        $this->ajaxRequest('/level-api/step-1', 'onCartData', ['cart_total' => 42])->assertOk();

        $this->get('/level-api/step-1')
            ->assertOk()
            ->assertSee('<span id="cart-total">42</span>', false);
    }

    public function test_the_exposed_step_number_enables_the_progress_title()
    {
        // The WAI multi-page forms tutorial's title pattern: progress first
        // ("Step 2 of 4"). stepNumber + steps|length build it in the template.
        $this->get('/level-api/step-1')
            ->assertOk()
            ->assertSee('<span id="step-num">1 of 3</span>', false);
    }

    public function test_save_data_with_an_unknown_step_fails_fast()
    {
        $response = $this->ajaxRequest('/level-api/step-1', 'onBadSave', []);

        $this->assertInstanceOf(ConfigurationException::class, $response->exception);
        $this->assertStringContainsString('nope', $response->exception->getMessage());
    }

    /**
     * A handler that writes a key to an EARLY step while a LATER step also
     * stores that key used to have no way to read its own write back: data()
     * merges the steps in config order, so the later step resolved the key.
     * The write was acknowledged (200) and the visible value did not change —
     * a success response after a hidden failure, which is how the
     * wizard-shop cart box kept painting a stale quantity.
     *
     * stepData() is the missing half of saveData(): it reads one step, so a
     * handler can always read back exactly what it wrote.
     */
    public function test_a_handler_can_read_back_the_step_it_wrote_even_when_a_later_step_shadows_the_key()
    {
        $this->ajaxRequest('/level-api/step-1', 'wizard::onNext', ['field1' => 'one'])->assertOk();
        $this->ajaxRequest('/level-api/step-2', 'wizard::onNext', ['field2' => 'two'])->assertOk();

        // The later step stores a 'shared' key of its own.
        $this->ajaxRequest('/level-api/step-2', 'onShadowedWrite', ['step' => 'step-2', 'value' => 'from-step-2'])
            ->assertOk();

        // The earlier step writes the same key.
        $payload = $this->ajaxRequest('/level-api/step-2', 'onShadowedWrite', ['step' => 'step-1', 'value' => 'from-step-1'])
            ->assertOk()
            ->json();

        // The merged view resolves 'shared' to step 2, the later step.
        $this->assertEquals('from-step-2', $payload['merged']['shared']);

        // The per-step read returns exactly what each step holds.
        $this->assertEquals('from-step-1', $payload['step1']['shared']);
        $this->assertEquals('from-step-2', $payload['step2']['shared']);
        $this->assertEquals('two', $payload['step2']['field2']);
    }

    /**
     * The write is never rejected: shadowing an earlier step is a legitimate
     * pattern (that is what a page does when it needs a value to outlive a
     * back navigation). Only the read is ambiguous, so only the read is
     * disambiguated. Failing the write would forbid a pattern that works.
     */
    public function test_a_write_that_a_later_step_shadows_is_still_accepted()
    {
        $this->ajaxRequest('/level-api/step-1', 'wizard::onNext', ['field1' => 'one'])->assertOk();
        $this->ajaxRequest('/level-api/step-2', 'wizard::onNext', ['field2' => 'two'])->assertOk();

        $response = $this->ajaxRequest('/level-api/step-2', 'onShadowedWrite', [
            'step' => 'step-1',
            'value' => 'from-step-1',
        ]);

        $response->assertOk();
        $this->assertNull($response->exception);
    }

    public function test_reading_an_unknown_step_fails_fast()
    {
        $response = $this->ajaxRequest('/level-api/step-1', 'onUnknownStepRead', []);

        $this->assertInstanceOf(ConfigurationException::class, $response->exception);
        $this->assertStringContainsString('nope', $response->exception->getMessage());
    }

    /**
     * The 1.x use case: the last step needs EVERY step's data to finish an
     * order, and in the 1.x that worked because the page read each value from
     * the one place it lived. stepsData() gives the same guarantee in 2.0:
     * the per-step map, where two steps may hold the same key name and neither
     * hides the other. Without it the developer had to loop over the step
     * codes and call stepData() on each — boilerplate that breaks silently
     * when a step is added.
     */
    public function test_every_step_can_be_read_at_once_without_anything_being_merged_away()
    {
        $this->ajaxRequest('/level-api/step-1', 'wizard::onNext', ['field1' => 'one'])->assertOk();
        $this->ajaxRequest('/level-api/step-2', 'wizard::onNext', ['field2' => 'two'])->assertOk();

        // Both steps now hold a key with the same name, different values.
        $this->ajaxRequest('/level-api/step-2', 'onShadowedWrite', ['step' => 'step-1', 'value' => 'from-step-1'])->assertOk();
        $this->ajaxRequest('/level-api/step-2', 'onShadowedWrite', ['step' => 'step-2', 'value' => 'from-step-2'])->assertOk();

        $steps = $this->ajaxRequest('/level-api/step-2', 'onAllSteps', [])
            ->assertOk()
            ->json()['steps'];

        // Each step keeps its own data, keyed by step code.
        $this->assertEquals('one', $steps['step-1']['field1']);
        $this->assertEquals('two', $steps['step-2']['field2']);
        $this->assertEquals('from-step-1', $steps['step-1']['shared']);
        $this->assertEquals('from-step-2', $steps['step-2']['shared']);

        // The same key name appears twice, with both values intact.
        $this->assertCount(2, $steps);
    }

    /**
     * An empty wizard returns an empty map, not an error: a handler can call
     * this before anything has been submitted.
     */
    public function test_reading_every_step_of_an_empty_wizard_returns_an_empty_map()
    {
        $steps = $this->ajaxRequest('/level-api/step-1', 'onAllSteps', [])
            ->assertOk()
            ->json()['steps'];

        $this->assertIsArray($steps);
    }

    public function test_flush_clears_only_the_wizard_zone()
    {
        $this->ajaxRequest('/level-api/step-1', 'onCartData', ['cart_total' => 7])->assertOk();

        $this->ajaxRequest('/level-api/step-1', 'onReset')->assertOk();

        $this->get('/level-api/step-1')
            ->assertOk()
            ->assertSee('<span id="cart-total"></span>', false);
    }

    public function test_deep_link_prefills_data_and_unlocks_the_requested_step()
    {
        // A fresh wizard cannot reach step 2: the onInit deep-link writes the
        // prefilled data, unlocks the step and the user lands on it.
        $this->get('/level-jump/step-2?store=7')->assertOk();

        $this->get('/level-jump/step-1')
            ->assertOk()
            ->assertSee('value="prefilled"', false);

        // The target step is exactly the one unlocked: step 3 is still gated.
        $this->get('/level-jump/step-3')->assertRedirect();
    }

    public function test_the_handler_can_override_the_destination_with_a_redirect()
    {
        $response = $this->ajaxRequest('/level-jump/step-1', 'wizard::onNext', ['field1' => 'skip']);

        $redirect = $response->json('X_WINTER_REDIRECT');
        $this->assertStringContainsString('/level-jump/step-3', $redirect);
        $this->assertStringContainsString('ref=campaign', $redirect);

        // The jumped-to step is unlocked and its prefilled data persisted.
        $this->get('/level-jump/step-3')->assertOk();
        $this->get('/level-jump/step-2')
            ->assertOk()
            ->assertSee('<span id="data-f2">auto</span>', false);
    }

    public function test_redirect_to_does_not_change_the_progress_pointer()
    {
        // Complete step 1 normally: step 2 becomes reachable.
        $this->ajaxRequest('/level-jump/step-1', 'wizard::onNext', ['field1' => 'ok'])->assertOk();

        // The guard handler sends the user back without touching the pointer.
        $redirect = $this->ajaxRequest('/level-jump/step-2', 'onRenderGuard')
            ->assertOk()
            ->json('X_WINTER_REDIRECT');
        $this->assertStringContainsString('/level-jump/step-1', $redirect);

        // Step 3 stays gated: redirectTo never unlocks steps.
        $this->get('/level-jump/step-3')->assertRedirect();
    }

    /**
     * A key stored under an old name has to be removable. saveData() merges,
     * so a rename left the old key in the session for the lifetime of the
     * wizard, where data() could still return it: a value no step wrote,
     * indistinguishable from a live one.
     */
    public function test_a_handler_can_remove_a_stored_key()
    {
        $this->ajaxRequest('/level-api/step-1', 'wizard::onNext', ['field1' => 'one'])->assertOk();
        $this->ajaxRequest('/level-api/step-1', 'onCartData', ['cart_total' => 99])->assertOk();

        $this->assertEquals(99, $this->ajaxRequest('/level-api/step-1', 'onAllSteps')
            ->assertOk()
            ->json('steps')['step-1']['cart_total']);

        $steps = $this->ajaxRequest('/level-api/step-1', 'onForget', ['step' => 'step-1', 'keys' => 'cart_total'])
            ->assertOk()
            ->json('steps');

        $this->assertArrayNotHasKey('cart_total', $steps['step-1']);
        $this->assertArrayHasKey('field1', $steps['step-1']);
    }

    /**
     * Without a step code the key leaves every step, which is the shape a
     * rename usually needs: the developer renaming a field rarely knows
     * every step that had written it.
     */
    public function test_forgetting_a_key_without_a_step_removes_it_from_every_step()
    {
        $this->ajaxRequest('/level-api/step-1', 'wizard::onNext', ['field1' => 'one'])->assertOk();
        $this->ajaxRequest('/level-api/step-2', 'wizard::onNext', ['field2' => 'two'])->assertOk();
        $this->ajaxRequest('/level-api/step-1', 'onShadowedWrite', ['step' => 'step-1', 'value' => 'from-step-1'])
            ->assertOk();
        $this->ajaxRequest('/level-api/step-2', 'onShadowedWrite', ['step' => 'step-2', 'value' => 'from-step-2'])
            ->assertOk();

        $steps = $this->ajaxRequest('/level-api/step-1', 'onForget', ['keys' => 'shared'])
            ->assertOk()
            ->json('steps');

        // Both steps held the key; both lost it.
        $this->assertArrayNotHasKey('shared', $steps['step-1']);
        $this->assertArrayNotHasKey('shared', $steps['step-2']);
        $this->assertEquals('one', $steps['step-1']['field1']);
        $this->assertEquals('two', $steps['step-2']['field2']);
    }

    /**
     * Asking to forget a key that was never stored is not an error: a forget
     * is idempotent, and the session may be behind the code that asks.
     */
    public function test_forgetting_a_key_that_is_not_stored_does_not_fail()
    {
        $this->ajaxRequest('/level-api/step-1', 'wizard::onNext', ['field1' => 'one'])->assertOk();

        $this->ajaxRequest('/level-api/step-1', 'onForget', ['step' => 'step-1', 'keys' => 'never-written'])
            ->assertOk();
    }

    /**
     * A step code the wizard does not declare is a configuration mistake, and
     * it fails the same way every other step code in the API fails: loudly.
     * Silently forgetting nothing would look identical to succeeding.
     */
    public function test_forgetting_with_an_unknown_step_fails_fast()
    {
        $response = $this->ajaxRequest('/level-api/step-1', 'onForgetUnknownStep', []);

        $this->assertInstanceOf(ConfigurationException::class, $response->exception);
        $this->assertStringContainsString('nope', $response->exception->getMessage());
    }

    /**
     * The handler guard, happy path: the step is reachable and the key is
     * there, so the handler gets the data in one call.
     */
    public function test_require_data_returns_the_data_when_the_step_is_reachable_and_holds_it()
    {
        $this->ajaxRequest('/level-api/step-1', 'wizard::onNext', ['field1' => 'one'])->assertOk();
        $this->ajaxRequest('/level-api/step-2', 'wizard::onNext', ['field2' => 'two'])->assertOk();

        $response = $this->ajaxRequest('/level-api/step-2', 'onRequire', ['step' => 'step-2']);

        $response->assertOk();
        $this->assertEquals('two', $response->json('required')['field2']);
    }

    /**
     * The guard fires when the step is not reachable yet — the same signal
     * the progress guard checked by hand, now fused with the read so it
     * cannot be left out of a handler that needs the data.
     */
    public function test_require_data_redirects_when_the_step_is_not_reachable()
    {
        // Nothing submitted: the wizard is at step 1, so step 2 is out of reach.
        $redirect = $this->ajaxRequest('/level-api/step-1', 'onRequire', ['step' => 'step-2'])
            ->assertOk()
            ->json('X_WINTER_REDIRECT');

        $this->assertStringContainsString('/level-api/step-1', $redirect);
    }

    /**
     * The other half of the data guard: a reachable step whose data is gone.
     * A wipe on back, or a jumpTo that unlocked the step without prefilling
     * it, both land here. Progress alone cannot catch it, which is why the
     * progress guard and the data guard are separate checks — and why this
     * method takes the keys as well as the step.
     */
    public function test_require_data_redirects_when_the_step_is_reachable_but_holds_none_of_the_data()
    {
        // step 1 completed, so step 2 is reachable...
        $this->ajaxRequest('/level-api/step-1', 'wizard::onNext', ['field1' => 'one'])->assertOk();

        // ...but field2 was never stored: going back and letting the wipe run
        // drops step 2 entirely, leaving it reachable with no data.
        $this->get('/level-api/step-1')->assertOk();
        $this->ajaxRequest('/level-api/step-2', 'onForget', ['step' => 'step-2'])->assertOk();

        $redirect = $this->ajaxRequest('/level-api/step-2', 'onRequire', ['step' => 'step-2'])
            ->assertOk()
            ->json('X_WINTER_REDIRECT');

        $this->assertStringContainsString('/level-api/step-2', $redirect);
    }

    /**
     * A step code the wizard does not declare is a programming error, and it
     * fails fast with the code in the message rather than quietly redirecting.
     */
    public function test_require_data_with_an_unknown_step_fails_fast()
    {
        $response = $this->ajaxRequest('/level-api/step-1', 'onRequireUnknownStep', []);

        $this->assertInstanceOf(ConfigurationException::class, $response->exception);
        $this->assertStringContainsString('nope', $response->exception->getMessage());
    }

    /**
     * The guard's predicate has to answer for BOTH shapes requireData()
     * returns, because the handler asks the question on every call.
     *
     * This is the regression test for a real defect: the predicate was first
     * written as a method ON the returned value, so `$data->isRedirect()`.
     * That reads well and is a fatal error on the success path, where $data is
     * an array — every successful call would have died with "Call to a member
     * function isRedirect() on array". Asking the component instead is safe on
     * both paths.
     */
    public function test_the_guard_predicate_answers_for_both_the_data_and_the_redirect()
    {
        $payload = $this->ajaxRequest('/level-api/step-1', 'onGuardProbe', [])
            ->assertOk()
            ->json();

        $this->assertFalse($payload['plain_array']);
        $this->assertFalse($payload['empty_array']);
        $this->assertTrue($payload['response']);
    }
}
