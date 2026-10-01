<?php

namespace Zimudec\Wizard\Tests\Acceptance;

use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * Custom validation messages reach the AJAX error fields end to end:
 * the step-level ->messages() override the generic framework messages,
 * and the app language override (lang/<locale>/system/validation.php)
 * replaces the generic messages of every wizard.
 */
class ValidationMessagesTest extends BaseTestCase
{
    public function test_custom_step_messages_reach_the_error_fields()
    {
        $this->get('/level-messages/step-1')->assertStatus(200);

        $this->assertAjaxException(
            $this->ajaxRequest('/level-messages/step-1', 'wizard::onNext', []),
            ['field1' => ['Enter your full name']]
        );
    }

    public function test_the_app_language_override_reaches_the_error_fields()
    {
        // The end-user recipe: a namespace override file at the app lang
        // directory (lang/en/system/validation.php) replaces the generic
        // messages of every validation in the application.
        $override = base_path('lang/en/system/validation.php');
        \File::makeDirectory(dirname($override), 0777, true, true);
        \File::put($override, "<?php\n\nreturn [\n    'required' => 'Este campo es requerido',\n];\n");

        $translator = $this->app['translator'];
        $resetCache = function () use ($translator) {
            $loaded = new \ReflectionProperty(\Illuminate\Translation\Translator::class, 'loaded');
            $loaded->setAccessible(true);
            $loaded->setValue($translator, []);
        };

        // Language files are cached once loaded: force a fresh load.
        $resetCache();

        try {
            $this->get('/level-1/step-1')->assertStatus(200);

            $this->assertAjaxException(
                $this->ajaxRequest('/level-1/step-1', 'wizard::onNext', []),
                [
                    'field1' => ['Este campo es requerido'],
                    'field2' => ['Este campo es requerido'],
                ]
            );
        } finally {
            \File::deleteDirectory(base_path('lang'));
            $resetCache();
        }
    }
}
