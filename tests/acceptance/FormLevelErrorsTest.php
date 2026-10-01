<?php

namespace Zimudec\Wizard\Tests\Acceptance;

use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * BUG (tanda 15, detectado por el usuario): a business-rule error whose key
 * has no field in the DOM (e.g. 'bundles' — the "at least one product"
 * cross-field rule) never reached the user. Snowboard's FormValidation only
 * paints messages into existing [data-validate-error="..."] bags; an orphan
 * key had no bag, so the submission failed silently.
 *
 * Fix contract: the step shell renders a form-level error container, and the
 * plugin's script paints orphan (DOM-less) field errors into it.
 */
class FormLevelErrorsTest extends BaseTestCase
{
    public function test_the_shell_renders_a_form_level_error_container()
    {
        $this->ajaxRequest('/level-shop/branch', 'wizard::onNext', ['store' => 'center']);

        $this->get('/level-shop/items')
            ->assertOk()
            ->assertSee('data-wizard-errors', false);
    }

    public function test_a_dom_less_business_rule_error_travels_in_the_error_fields()
    {
        $this->ajaxRequest('/level-shop/branch', 'wizard::onNext', ['store' => 'center']);

        // Empty quantities pass the nullable field rules; the at-least-one
        // business rule fails on a key with no DOM field.
        $this->assertAjaxException(
            $this->ajaxRequest('/level-shop/items', 'wizard::onNext', [
                'quantity_widget' => '',
                'quantity_gadget' => '',
            ]),
            ['bundles' => ['Select at least one product']]
        );
    }

    public function test_field_errors_still_carry_their_own_messages()
    {
        // The field-level bags keep working: an invalid store fails on the
        // store key, which HAS a DOM bag.
        $this->assertAjaxException(
            $this->ajaxRequest('/level-shop/branch', 'wizard::onNext', ['store' => 'ghost']),
            ['store' => ['That store is not available']]
        );
    }
}
