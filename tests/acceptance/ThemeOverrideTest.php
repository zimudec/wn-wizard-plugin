<?php

namespace Zimudec\Wizard\Tests\Acceptance;

use Cms\Classes\Theme;
use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * Convention of component partial overrides per theme in Winter 1.2,
 * verified against the implementation (Cms\Classes\ComponentPartial::
 * loadOverrideCached via Cms\Classes\Controller::renderPartial): the theme
 * replaces a component partial by placing it at
 * "partials/<componentAlias>/<partialName>.htm". For this component the
 * override routes are:
 *
 *   themes/<theme>/partials/wizard/default.htm        (step shell)
 *   themes/<theme>/partials/wizard/nav.htm            (step indicator)
 *   themes/<theme>/partials/wizard/buttons.htm        (navigation buttons)
 *   themes/<theme>/partials/wizard/field.htm          (field dispatcher)
 *   themes/<theme>/partials/wizard/field_<type>.htm   (field per type:
 *                                                      field_text.htm,
 *                                                      field_select.htm, ...)
 */
class ThemeOverrideTest extends BaseTestCase
{
    public function test_a_theme_partial_replaces_the_plugin_partial()
    {
        Theme::setActiveTheme('wizard-override-fixture');

        $content = $this->get('/level-override/step-1')->assertStatus(200)->getContent();

        // The theme partial renders instead of the plugin partial.
        $this->assertStringContainsString('theme-override-text-field', $content);
        $this->assertStringNotContainsString('wizard__field', $content);
    }

    public function test_the_plugin_partial_renders_without_a_theme_override()
    {
        Theme::setActiveTheme('wizard-fixture');

        $content = $this->get('/level-1/step-1')->assertStatus(200)->getContent();

        $this->assertStringContainsString('wizard__input', $content);
        $this->assertStringNotContainsString('theme-override-text-field', $content);
    }
}
