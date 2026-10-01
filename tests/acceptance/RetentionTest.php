<?php

namespace Zimudec\Wizard\Tests\Acceptance;

use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * Going back with retention (retainData): the completed steps are navigable
 * from the indicator (clickable links) and the data of the later steps
 * remains in the session when returning to a previous step.
 */
class RetentionTest extends BaseTestCase
{
    public function test_completed_steps_are_clickable_with_retention()
    {
        session([$this->wizardSessionKey('level-retain') => ['data' => [], 'meta' => ['stepCurrent' => 1]]]);

        $content = $this->get('/level-retain/step-1')->assertStatus(200)->getContent();

        // The completed and current steps are links in the indicator.
        $this->assertMatchesRegularExpression(
            '/<a class="wizard__nav-link" href="[^"]*\/step-1">Step 1<\/a>/',
            $content
        );

        // The pending steps are never clickable.
        $this->assertMatchesRegularExpression(
            '/<span class="wizard__nav-label">Step 2<\/span>/',
            $content
        );
    }

    public function test_data_of_later_steps_is_retained_when_going_back()
    {
        session([$this->wizardSessionKey('level-retain') => [
            'data' => [
                'step-1' => ['field1' => 'one'],
                'step-2' => ['field3' => 'retained'],
            ],
            'meta' => ['stepCurrent' => 2],
        ]]);

        $this->get('/level-retain/step-1')->assertStatus(200);

        $state = session($this->wizardSessionKey('level-retain'));
        $this->assertEquals('retained', $state['data']['step-2']['field3']);
    }

    public function test_completed_steps_are_not_clickable_with_the_privacy_default()
    {
        session([$this->wizardSessionKey('level-0') => ['data' => [], 'meta' => ['stepCurrent' => 1]]]);

        $content = $this->get('/level-0/step-1')->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('wizard__nav-link', $content);
    }

    /**
     * Round 42: retention declared from a page that uses define() (levels 1 and
     * 2). Until the builder could carry the wizard-wide settings, this page
     * could not keep the data of the later steps at all: the component property
     * was not read once define() was used, and the builder had no method for
     * it, so the wizard silently fell back to the wipe-on-back default. The
     * only coverage this setting ever had was a level 0 fixture, which is the
     * one level where the property was honored.
     */
    public function test_retention_declared_in_the_builder_keeps_the_later_steps()
    {
        $this->ajaxRequest('/level-retain-builder/step-1', 'wizard::onNext', ['field1' => 'one'])
            ->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/level-retain-builder/step-2'));

        $this->ajaxRequest('/level-retain-builder/step-2', 'wizard::onNext', ['field2' => 'two'])
            ->assertOk();

        // Going back to step 1 keeps what step 2 stored.
        $this->get('/level-retain-builder/step-1')->assertStatus(200);

        $state = session($this->wizardSessionKey('level-retain-builder'));
        $this->assertEquals('two', $state['data']['step-2']['field2']);

        // And the retention reaches the view: the completed steps are links.
        $content = $this->get('/level-retain-builder/step-1')->getContent();
        $this->assertMatchesRegularExpression(
            '/<a class="wizard__nav-link" href="[^"]*\/step-1">Step 1<\/a>/',
            $content
        );
    }

    /**
     * The other half of the round 42 fix: a setting the page does NOT declare
     * in the builder falls back to the component property, so the property is
     * honored on a page that uses define(). Before the fix resolveConfig()
     * picked the builder config wholesale and the property was never read.
     */
    public function test_a_property_is_honored_on_a_page_that_uses_define()
    {
        $this->ajaxRequest('/level-retain-property/step-1', 'wizard::onNext', ['field1' => 'one'])
            ->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/level-retain-property/step-2'));

        $this->ajaxRequest('/level-retain-property/step-2', 'wizard::onNext', ['field2' => 'two'])
            ->assertOk();

        $this->get('/level-retain-property/step-1')->assertStatus(200);

        $state = session($this->wizardSessionKey('level-retain-property'));
        $this->assertEquals('two', $state['data']['step-2']['field2']);
    }

    /**
     * And the builder still wins when the page declares the setting there, so
     * the two surfaces cannot disagree silently in the other direction: this
     * page calls define() and sets retainData="0", and the data is wiped.
     */
    public function test_the_builder_declaration_wins_over_the_property()
    {
        $this->ajaxRequest('/level-retain-override/step-1', 'wizard::onNext', ['field1' => 'one'])
            ->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/level-retain-override/step-2'));

        $this->ajaxRequest('/level-retain-override/step-2', 'wizard::onNext', ['field2' => 'two'])
            ->assertOk();

        $this->get('/level-retain-override/step-1')->assertStatus(200);

        $state = session($this->wizardSessionKey('level-retain-override'));
        $this->assertArrayNotHasKey('step-2', $state['data']);
    }

    /**
     * Round 43: the narrow retention case. The wipe boundary is positional and
     * used to be all-or-nothing, so a flow that needed the data of ONE later
     * step (the rows of a grid it re-reads) had to either retain every later
     * step or write a copy of the data into an earlier step to keep it alive.
     * The copy is the silent failure: forget it and the derived data is gone
     * with no warning.
     *
     * This is the shape the wizard-shop sample needed, and the reason the
     * sample carried that copy.
     */
    public function test_a_named_step_survives_the_wipe_while_the_other_later_step_does_not()
    {
        $this->ajaxRequest('/level-keep-steps/step-1', 'wizard::onNext', ['field1' => 'one'])
            ->assertOk()
            ->assertJsonPath('X_WINTER_REDIRECT', url('/level-keep-steps/step-2'));

        $this->ajaxRequest('/level-keep-steps/step-2', 'wizard::onNext', ['field2' => 'two'])
            ->assertOk();

        $this->ajaxRequest('/level-keep-steps/step-3', 'wizard::onNext', ['field3' => 'three'])
            ->assertOk();

        // Going back to step 1: step 2 was named, step 3 was not.
        $this->get('/level-keep-steps/step-1')->assertStatus(200);

        $state = session($this->wizardSessionKey('level-keep-steps'));
        $this->assertEquals('one', $state['data']['step-1']['field1']);
        $this->assertEquals('two', $state['data']['step-2']['field2']);
        $this->assertArrayNotHasKey('step-3', $state['data']);

        // The completed steps are still not links: keepSteps narrows the
        // wipe, it does not make the stepper editable like retainData does.
        $content = $this->get('/level-keep-steps/step-1')->getContent();
        $this->assertStringNotContainsString('wizard__nav-link', $content);
    }

    /**
     * The same setting has to be reachable without writing a page: a level 0
     * wizard declares it in the component properties. Otherwise the flows that
     * cannot add code to a page would still need the copy-into-another-step
     * workaround.
     */
    public function test_a_named_step_can_be_kept_from_the_component_properties()
    {
        $this->ajaxRequest('/level-keep-steps-property/step-1', 'wizard::onNext', ['field1' => 'one'])
            ->assertOk();

        $this->ajaxRequest('/level-keep-steps-property/step-2', 'wizard::onNext', ['field1' => 'two'])
            ->assertOk();

        $this->get('/level-keep-steps-property/step-1')->assertStatus(200);

        $state = session($this->wizardSessionKey('level-keep-steps-property'));
        $this->assertEquals('two', $state['data']['step-2']['field1']);
    }
}
