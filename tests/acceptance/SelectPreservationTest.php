<?php

namespace Zimudec\Wizard\Tests\Acceptance;

use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * Bug B1 of 1.0.7: a select field lost its selection when returning to the
 * step. In 2.0 the selection is restored from the wizard session data with
     * the correct "selected" attribute.
 */
class SelectPreservationTest extends BaseTestCase
{
    public function test_select_renders_the_options()
    {
        $content = $this->get('/level-1/step-1')->assertStatus(200)->getContent();

        $this->assertStringContainsString('Option A', $content);
        $this->assertStringContainsString('Option B', $content);
        $this->assertStringNotContainsString('selected', str_replace('data-validate-for', '', $content) ?? $content);
    }

    public function test_select_keeps_the_selection_when_returning_to_the_step()
    {
        $this->ajaxRequest('/level-1/step-1', 'wizard::onNext', [
            'field1' => 'value',
            'field2' => 'b',
        ])->assertOk()->assertJsonPath('X_WINTER_REDIRECT', url('/level-1/step-2'));

        // Returning to the first step restores the selection.
        $content = $this->get('/level-1/step-1')->assertStatus(200)->getContent();

        $this->assertMatchesRegularExpression(
            '/<option value="b"[^>]*selected[^>]*>Option B<\/option>/s',
            $content
        );
    }
}
