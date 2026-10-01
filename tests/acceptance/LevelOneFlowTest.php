<?php

namespace Zimudec\Wizard\Tests\Acceptance;

use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * Level 1 e2e: a declarative wizard (fields, rules, commit) built by the
 * page — no request handlers anywhere — completes the full flow
 * (GET → submit → redirect → session → last step → cleared session).
 */
class LevelOneFlowTest extends BaseTestCase
{
    public function test_the_full_flow_completes_and_clears_the_session()
    {
        // Land on the first step.
        $this->get('/level-1')->assertStatus(302)->assertRedirect('/level-1/step-1');

        // Submit the first step: invalid first...
        $this->assertAjaxException(
            $this->ajaxRequest('/level-1/step-1', 'wizard::onNext', ['field1' => 'value']),
            ['field2' => ['The field2 field is required.']]
        );

        // ...then valid: the flow advances.
        $this->ajaxRequest('/level-1/step-1', 'wizard::onNext', [
            'field1' => 'value',
            'field2' => 'a',
        ])->assertOk()->assertJsonPath('X_WINTER_REDIRECT', url('/level-1/step-2'));

        $state = session($this->wizardSessionKey('level-1'));
        $this->assertEquals('value', $state['data']['step-1']['field1']);

        // Render the second step, then finish it: the state clears.
        $this->get('/level-1/step-2')->assertStatus(200)->assertSee('Step 2', false);

        $this->ajaxRequest('/level-1/step-2', 'wizard::onNext', ['field3' => 'value'])
            ->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/'));

        $this->assertNull(session($this->wizardSessionKey('level-1')));
    }
}
