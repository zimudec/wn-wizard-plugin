<?php

namespace Zimudec\Wizard\Tests\Acceptance;

use Event;
use ValidationException;
use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * The wizard cycle events (wizard.beforeValidate, wizard.afterCommit,
 * wizard.stepCompleted) fire with the corresponding data and the listeners
 * can affect the flow (e.g. block the advance from beforeValidate).
 */
class WizardEventsTest extends BaseTestCase
{
    public function test_cycle_events_fire_with_the_step_data()
    {
        $fired = [];

        Event::listen('wizard.beforeValidate', function ($wizard, $step, $data) use (&$fired) {
            $fired[] = ['beforeValidate', $step->code, $data];
        });

        Event::listen('wizard.afterCommit', function ($wizard, $step, $data) use (&$fired) {
            $fired[] = ['afterCommit', $step->code, $data];
        });

        Event::listen('wizard.stepCompleted', function ($wizard, $step, $data) use (&$fired) {
            $fired[] = ['stepCompleted', $step->code, $data];
        });

        $this->ajaxRequest('/level-2/step-1', 'wizard::onNext', ['field1' => 'value'])
            ->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/level-2/step-2'));

        $events = array_column($fired ?? [], 0);
        $this->assertEquals(['beforeValidate', 'afterCommit', 'stepCompleted'], $events);
        $this->assertEquals('step-1', $fired[0][1]);
        $this->assertEquals('value', $fired[0][2]['field1'] ?? null);
    }

    /**
     * The two sibling events have different data contracts, and the names
     * invite the wrong expectation: afterCommit fires BEFORE the step data is
     * persisted (it reports the state right after the commit hook ran), while
     * stepCompleted fires AFTER it. A listener that needs the persisted data
     * must use stepCompleted.
     */
    public function test_after_commit_sees_the_state_before_persistence_and_step_completed_after_it()
    {
        $seen = [];

        Event::listen('wizard.afterCommit', function ($wizard, $step, $data) use (&$seen) {
            $seen['afterCommit'] = $data;
        });

        Event::listen('wizard.stepCompleted', function ($wizard, $step, $data) use (&$seen) {
            $seen['stepCompleted'] = $data;
        });

        $this->ajaxRequest('/level-2/step-1', 'wizard::onNext', ['field1' => 'value'])
            ->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/level-2/step-2'));

        // afterCommit: the step that is being submitted is not in the store yet.
        $this->assertArrayNotHasKey('field1', $seen['afterCommit']);

        // stepCompleted: the same step is now persisted.
        $this->assertEquals('value', $seen['stepCompleted']['field1']);

        // Both read the same store, which agrees with the session.
        $this->assertEquals(
            'value',
            session($this->wizardSessionKey('level-2'))['data']['step-1']['field1']
        );
    }

    /**
     * The commit hook takes TWO data arguments, and reading the current step
     * from the second one fails silently: the second argument is the
     * accumulated data of the PREVIOUS steps, so the current step's own
     * derived data is simply absent and no error is reported anywhere. Found
     * porting the shop sample, where the order was created with the total
     * before the discount.
     */
    public function test_the_commit_hook_receives_the_current_step_in_the_first_argument()
    {
        session(['level2_commit_args' => null]);

        $this->ajaxRequest('/level-2/step-1', 'wizard::onNext', ['field1' => 'value'])
            ->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/level-2/step-2'));

        $args = session('level2_commit_args');

        // First argument: this step's data merged with its derived data.
        $this->assertEquals('value', $args['current']['field1']);
        $this->assertEquals('from-extra-validation', $args['current']['derived_mark']);

        // Second argument: the previous steps only — step-1 is the first one,
        // so the accumulated data is empty and the step is NOT in it.
        $this->assertArrayNotHasKey('field1', $args['previous']);
        $this->assertArrayNotHasKey('derived_mark', $args['previous']);
    }

    public function test_a_before_validate_listener_can_block_the_advance()
    {
        Event::listen('wizard.beforeValidate', function ($wizard, $step, $data) {
            throw new ValidationException(['field1' => 'Blocked by the listener']);
        });

        $response = $this->ajaxRequest('/level-2/step-1', 'wizard::onNext', ['field1' => 'value']);

        $this->assertAjaxException($response, [
            'field1' => ['Blocked by the listener'],
        ]);

        // The step did not advance.
        $this->assertNull(session($this->wizardSessionKey('level-2')));
    }
}
