<?php

namespace Zimudec\Wizard\Tests\Acceptance;

use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * The component scopes its session by the page that declares it and by the
 * component alias, so two wizards declared on different pages keep separate
 * progress and separate data. The two fixtures used here declare the same
 * component and the same step codes, so the only thing telling them apart is
 * the page.
 */
class WizardIsolationTest extends BaseTestCase
{
    private function seedLevelZeroSession(): void
    {
        session([
            $this->wizardSessionKey('level-0') => [
                'data' => ['step-1' => ['field1' => 'from-level-0']],
                'meta' => ['stepCurrent' => 2],
            ],
        ]);
    }

    public function test_progress_on_one_page_does_not_reach_a_step_on_another()
    {
        $this->seedLevelZeroSession();

        // level-0 sits on its second step, but level-1 has no state at all,
        // so its second step is still out of reach.
        $this->get('/level-1/step-2')
            ->assertStatus(302)
            ->assertRedirect('/level-1/step-1');
    }

    public function test_data_of_one_page_does_not_render_on_another()
    {
        $this->seedLevelZeroSession();

        $this->get('/level-1/step-1')
            ->assertStatus(200)
            ->assertDontSee('from-level-0', false);
    }

    public function test_the_isolation_holds_in_the_other_direction_too()
    {
        session([
            $this->wizardSessionKey('level-1') => [
                'data' => ['step-1' => ['field1' => 'from-level-1']],
                'meta' => ['stepCurrent' => 2],
            ],
        ]);

        $this->get('/level-0/step-2')
            ->assertStatus(302)
            ->assertRedirect('/level-0/step-1');
    }
}
