<?php

namespace Zimudec\Wizard\Tests\Acceptance;

use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * North-star acceptance test of Level 0: a working wizard configured ONLY
 * from component properties (no code in the page).
 */
class LevelZeroTest extends BaseTestCase
{
    public function test_wizard_by_properties_navigates_without_page_code()
    {
        $this->get('/level-0')->assertStatus(302)->assertRedirect('/level-0/step-1');

        $this->get('/level-0/step-1')->assertStatus(200)->assertSee('Step 1', false);

        $this->ajaxRequest('/level-0/step-1', 'wizard::onNext')
            ->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/level-0/step-2'));

        $this->get('/level-0/step-2')->assertStatus(200)->assertSee('Step 2', false);
    }
}
