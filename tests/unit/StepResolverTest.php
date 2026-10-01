<?php

namespace Zimudec\Wizard\Tests\Unit;

use Cms\Classes\Page;
use Cms\Classes\Theme;
use Zimudec\Wizard\Support\StepResolver;
use Zimudec\Wizard\Support\WizardConfig;
use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * Unit tests of the step resolver: URL parameter mapping, navigation URLs
 * (previous/next/finish) and gating of unknown steps.
 */
class StepResolverTest extends BaseTestCase
{
    protected function makeResolver(array $steps = ['step-1', 'step-2', 'step-3']): StepResolver
    {
        return new StepResolver(WizardConfig::fromProperties([
            'steps' => implode('|', $steps),
            'titles' => implode('|', array_map(fn (string $s) => 'Title ' . $s, $steps)),
            'finishUrl' => '/thanks',
        ]), Page::load(Theme::load('wizard-fixture'), 'level-0.htm'));
    }

    public function test_resolves_position_by_url_code()
    {
        $resolver = $this->makeResolver();

        $this->assertEquals(0, $resolver->position('step-1'));
        $this->assertEquals(2, $resolver->position('step-3'));
    }

    public function test_unknown_code_resolves_to_null()
    {
        $this->assertNull($this->makeResolver()->position('isnt-a-step'));
        $this->assertNull($this->makeResolver()->position(''));
        $this->assertNull($this->makeResolver()->position(null));
    }

    public function test_navigation_urls_point_to_the_page_with_the_step_parameter()
    {
        $resolver = $this->makeResolver();

        $this->assertEquals(url('/level-0/step-2'), $resolver->nextUrl(0));
        $this->assertEquals(url('/level-0/step-1'), $resolver->prevUrl(1));
    }

    public function test_next_url_is_null_on_the_last_step_and_prev_url_on_the_first()
    {
        $resolver = $this->makeResolver();

        $this->assertNull($resolver->nextUrl(2));
        $this->assertNull($resolver->prevUrl(0));
    }

    public function test_first_and_last_detection()
    {
        $resolver = $this->makeResolver();

        $this->assertTrue($resolver->isLast(2));
        $this->assertFalse($resolver->isLast(0));
    }

    public function test_finish_url_defaults_to_home()
    {
        $this->assertEquals('/', WizardConfig::fromProperties(['steps' => 'a'])->finishUrl);
    }
}
