<?php

namespace Zimudec\Wizard\Tests\Unit;

use Zimudec\Wizard\Support\Builder;
use Zimudec\Wizard\Support\ConfigurationException;
use Zimudec\Wizard\Support\WizardConfig;
use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * Unit tests of the Level 0 property parsing into WizardConfig.
 */
class WizardConfigTest extends BaseTestCase
{
    public function test_parses_steps_titles_and_fields_from_properties()
    {
        $config = WizardConfig::fromProperties([
            'steps' => 'step-1|step-2',
            'titles' => 'First|Second',
            'fields' => 'field1|field2',
        ]);

        $this->assertCount(2, $config->steps);
        $this->assertEquals('step-1', $config->steps[0]->code);
        $this->assertEquals('First', $config->steps[0]->title);
        $this->assertEquals('Second', $config->steps[1]->title);

        $this->assertEquals(['field1', 'field2'], $config->steps[0]->fieldNames());
        $this->assertEquals('text', $config->steps[0]->fields[0]->type);
        $this->assertEquals([], $config->steps[0]->rules);
    }

    public function test_missing_titles_fall_back_to_the_step_code()
    {
        $config = WizardConfig::fromProperties([
            'steps' => 'step-1|step-2',
        ]);

        $this->assertEquals('step-1', $config->steps[0]->title);
        $this->assertEquals('step-2', $config->steps[1]->title);
    }

    public function test_defaults()
    {
        $config = WizardConfig::fromProperties([
            'steps' => 'step-1|step-2',
        ]);

        $this->assertEquals('/', $config->finishUrl);
        $this->assertNull($config->ttl);
        $this->assertTrue($config->wipeOnBack);
        $this->assertFalse($config->retainData);
        $this->assertFalse($config->encrypted);
    }

    /**
     * The four wizard-wide settings are documented as COMPONENT PROPERTIES
     * and WizardConfig::fromArray() reads all four keys from the builder
     * config — but Builder::toArray() used to emit only `steps` and
     * `finishUrl`, and Wizard::resolveConfig() used the builder config
     * INSTEAD of the properties whenever the page called define(). On levels 1
     * and 2 there was therefore no way at all to reach them: not through the
     * builder (no method existed) and not through the property (it was not
     * read). They fell back to the defaults, silently.
     *
     * The practical damage was a privacy control that looked enabled and was
     * not: privacy.md tells the developer to set `encrypted: true` and
     * `retainData`, and on a level 1/2 page both were inert with no error and
     * no warning. Round 42 fix: the builder declares them, and a setting the
     * page leaves alone falls back to the property.
     */
    public function test_the_builder_carries_the_wizard_wide_settings()
    {
        $builder = new Builder();
        $builder
            ->step('step-1', 'Step 1')
            ->end()
            ->ttl(15)
            ->wipeOnBack(false)
            ->retainData()
            ->encrypted();

        $config = WizardConfig::fromArray($builder->toArray());

        $this->assertSame(15, $config->ttl);
        $this->assertFalse($config->wipeOnBack);
        $this->assertTrue($config->retainData);
        $this->assertTrue($config->encrypted);
    }

    /**
     * A setting the page did not declare is absent from the builder array, not
     * present with a default: that absence is what lets resolveConfig() tell
     * "declared as false" apart from "not declared" and fall back to the
     * component property. Defaulting it inside the builder would make a
     * property unreachable again.
     */
    public function test_the_builder_omits_the_settings_the_page_did_not_declare()
    {
        $builder = new Builder();
        $builder->step('step-1', 'Step 1')->end()->retainData();

        $config = $builder->toArray();

        $this->assertArrayHasKey('retainData', $config);
        $this->assertArrayNotHasKey('wipeOnBack', $config);
        $this->assertArrayNotHasKey('ttl', $config);
        $this->assertArrayNotHasKey('encrypted', $config);
        $this->assertArrayNotHasKey('finishUrl', $config);
    }

    /**
     * The narrow retention case. The wipe boundary is positional, so before
     * this option a page that needed the data of ONE later step had to either
     * retain everything (`retainData`, which also makes the completed steps
     * clickable and re-shows the personal data of the step after it) or write
     * a copy of the data into an earlier step to keep it alive. The copy is
     * the silent failure: forget it and the derived data is gone with no
     * warning. Here the page names the step it keeps and writes no copy.
     */
    public function test_the_builder_carries_the_steps_to_keep()
    {
        $builder = new Builder();
        $builder
            ->step('step-1', 'Step 1')
            ->end()
            ->step('step-2', 'Step 2')
            ->end()
            ->step('step-3', 'Step 3')
            ->end()
            ->keepSteps(['step-3']);

        $config = WizardConfig::fromArray($builder->toArray());

        $this->assertSame(['step-3'], $config->keepSteps);
    }

    /**
     * A kept step that does not exist can never match anything, so it would be
     * dead configuration the page believes is protecting its data. Fail fast,
     * like jumpTo() and redirectTo() do with an unknown step.
     */
    public function test_keeping_a_step_that_does_not_exist_throws()
    {
        $builder = new Builder();
        $builder
            ->step('step-1', 'Step 1')
            ->end()
            ->keepSteps(['step-9']);

        $this->expectException(\Zimudec\Wizard\Support\ConfigurationException::class);
        $this->expectExceptionMessage('step-9');

        WizardConfig::fromArray($builder->toArray());
    }

    public function test_empty_steps_throw_a_translated_exception()
    {
        $this->expectException(\Zimudec\Wizard\Support\ConfigurationException::class);
        $this->expectExceptionMessage('The wizard has no steps configured');

        WizardConfig::fromProperties(['steps' => '']);
    }

    public function test_ttl_parsing()
    {
        $this->assertNull(WizardConfig::fromProperties(['steps' => 'a', 'ttl' => ''])->ttl);
        $this->assertEquals(15, WizardConfig::fromProperties(['steps' => 'a', 'ttl' => '15'])->ttl);
        $this->assertEquals(0, WizardConfig::fromProperties(['steps' => 'a', 'ttl' => '0'])->ttl);
        $this->assertNull(WizardConfig::fromProperties(['steps' => 'a'])->ttl);
    }

    public function test_comma_separated_lists_are_accepted()
    {
        $config = WizardConfig::fromProperties([
            'steps' => 'step-1,step-2',
            'titles' => 'A,B',
        ]);

        $this->assertEquals(['step-1', 'step-2'], array_column($config->steps, 'code'));
        $this->assertEquals(['A', 'B'], array_column($config->steps, 'title'));
    }
}
