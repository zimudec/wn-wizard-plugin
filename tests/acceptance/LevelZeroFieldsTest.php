<?php

namespace Zimudec\Wizard\Tests\Acceptance;

use Zimudec\Wizard\Support\FieldConfig;
use Zimudec\Wizard\Support\StepConfig;
use Zimudec\Wizard\Support\WizardConfig;
use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * Level 0 e2e: the property `fields` renders text inputs without validation
 * rules (rules are never inferred) and persists the values the user types, so
 * they are repopulated when they come back to the step; the full property set
 * (steps, titles, finishUrl, ttl, retainData, encrypted) is parsed into the
 * same WizardConfig the other levels produce.
 */
class LevelZeroFieldsTest extends BaseTestCase
{
    public function test_fields_property_renders_text_inputs_without_rules()
    {
        $content = $this->get('/level-0/step-1')->assertStatus(200)->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*name="field1"/s', $content);
        $this->assertMatchesRegularExpression('/<input[^>]*name="field2"/s', $content);

        // The parsed config has no rules (validation is never invented).
        $config = WizardConfig::fromProperties(['steps' => 'a|b', 'fields' => 'f1']);
        $this->assertEquals([], $config->steps[0]->rules);
        $this->assertEquals(['f1'], $config->steps[0]->fieldNames());
    }

    /**
     * A declared field persists even without a rule: persistence and
     * validation are orthogonal, and a value the user typed is not thrown
     * away. Nothing is inferred, so no rule is applied to it.
     */
    public function test_submitting_a_properties_wizard_persists_its_declared_fields()
    {
        $this->ajaxRequest('/level-0/step-1', 'wizard::onNext', [
            'field1' => 'value',
            'unknown' => 'discarded',
        ])->assertOk()->assertJsonPath('X_WINTER_REDIRECT', url('/level-0/step-2'));

        $state = session($this->wizardSessionKey('level-0'));
        $this->assertEquals(['field1' => 'value'], $state['data']['step-1']);
        $this->assertEquals(1, $state['meta']['stepCurrent']);
    }

    /**
     * End to end: the value the user typed is repopulated when they come back
     * to the step. Going back to a previous step with the default wipe-on-back
     * does not clear the step itself.
     */
    public function test_the_fields_property_persists_its_values()
    {
        $this->ajaxRequest('/level-0/step-1', 'wizard::onNext', ['field1' => 'typed value'])
            ->assertOk();

        $content = $this->get('/level-0/step-1')->assertStatus(200)->getContent();

        $this->assertStringContainsString('value="typed value"', $content);
    }

    public function test_properties_parse_into_the_same_config_shape_as_the_builder()
    {
        $fromProperties = WizardConfig::fromProperties([
            'steps' => 'step-1|step-2',
            'titles' => 'A|B',
            'finishUrl' => '/done',
            'ttl' => '10',
            'retainData' => '1',
            'encrypted' => '1',
            'wipeOnBack' => '0',
        ]);

        $this->assertEquals('/done', $fromProperties->finishUrl);
        $this->assertEquals(10, $fromProperties->ttl);
        $this->assertTrue($fromProperties->retainData);
        $this->assertTrue($fromProperties->encrypted);
        $this->assertFalse($fromProperties->wipeOnBack);
        $this->assertInstanceOf(StepConfig::class, $fromProperties->steps[0]);
        $this->assertEquals([], $fromProperties->steps[0]->fields);
    }
}
