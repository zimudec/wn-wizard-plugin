<?php

namespace Zimudec\Wizard\Tests\Unit;

use ValidationException;
use Zimudec\Wizard\Support\SessionStore;
use Zimudec\Wizard\Support\StepConfig;
use Zimudec\Wizard\Support\StepValidator;
use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * Unit tests of the per-step validation pipeline: base rules with custom
 * messages, extra validation with access to the accumulated data, and the
 * commit hook. Errors are delivered through ValidationException.
 */
class StepValidationTest extends BaseTestCase
{
    public function test_rules_validate_the_current_step()
    {
        $step = new StepConfig(
            code: 'step-1',
            rules: ['field1' => 'required'],
        );

        $validator = new StepValidator();

        $this->assertEquals([], $validator->validate($step, ['field1' => 'value']));
    }

    public function test_failed_rules_throw_a_validation_exception_with_field_errors()
    {
        $step = new StepConfig(
            code: 'step-1',
            rules: ['field1' => 'required'],
        );

        try {
            (new StepValidator())->validate($step, []);
            $this->fail('ValidationException was not thrown.');
        } catch (\Winter\Storm\Exception\ValidationException $exception) {
            $this->assertArrayHasKey('field1', $exception->getFields());
        }
    }

    public function test_custom_messages_replace_the_generic_ones()
    {
        $step = new StepConfig(
            code: 'step-1',
            rules: ['field1' => 'required'],
            messages: ['field1.required' => 'Custom message for field1'],
        );

        try {
            (new StepValidator())->validate($step, []);
            $this->fail('ValidationException was not thrown');
        } catch (\Winter\Storm\Exception\ValidationException $exception) {
            $this->assertEquals(
                ['field1' => ['Custom message for field1']],
                $exception->getFields()
            );
        }
    }

    public function test_extra_validation_runs_after_the_base_rules_with_accumulated_data()
    {
        $step = new StepConfig(
            code: 'step-1',
            rules: ['field1' => 'required'],
            extraValidation: function ($validator, $data) {
                if ($data['accumulated'] !== 'known') {
                    $validator->errors()->add('field1', 'The accumulated data is invalid');
                }
            },
        );

        $validatorService = new StepValidator();

        // Extra validation sees the accumulated data.
        $this->assertEquals([], $validatorService->validate(
            $step,
            ['field1' => 'value'],
            ['accumulated' => 'known', 'field1' => 'value']
        ));

        // A failure in the extra validation does not advance the step.
        try {
            $validatorService->validate(
                $step,
                ['field1' => 'value'],
                ['accumulated' => 'wrong', 'field1' => 'value']
            );
            $this->fail('ValidationException was not thrown');
        } catch (\Winter\Storm\Exception\ValidationException $exception) {
            $this->assertArrayHasKey('field1', $exception->getFields());
            $this->assertStringContainsString('accumulated data is invalid', head($exception->getFields()['field1']));
        }
    }

    public function test_extra_validation_can_return_derived_data()
    {
        $step = new StepConfig(
            code: 'step-1',
            rules: ['field1' => 'required'],
            extraValidation: fn ($validator, $data) => ['derived' => $data['field1'] . '-derived'],
        );

        $derived = (new StepValidator())->validate($step, ['field1' => 'value']);

        $this->assertEquals(['derived' => 'value-derived'], $derived);
    }

    public function test_commit_runs_once_per_successful_submit()
    {
        $calls = 0;
        $step = new StepConfig(
            code: 'step-1',
            rules: ['field1' => 'required'],
            commit: function ($input, $accumulated) use (&$calls) {
                $calls++;
            },
        );

        $validatorService = new StepValidator();
        $validatorService->validate($step, ['field1' => 'value']);
        $validatorService->commit($step, ['field1' => 'value']);

        $this->assertEquals(1, $calls);
    }

    public function test_a_validation_error_inside_commit_blocks_the_advance()
    {
        $step = new StepConfig(
            code: 'step-1',
            rules: ['field1' => 'required'],
            commit: fn ($input, $accumulated) => throw new \Winter\Storm\Exception\ValidationException(['field1' => 'Commit rejected']),
        );

        try {
            (new StepValidator())->commit($step, ['field1' => 'value']);
            $this->fail('ValidationException was not thrown');
        } catch (\Winter\Storm\Exception\ValidationException $exception) {
            $this->assertArrayHasKey('field1', $exception->getFields());
        }
    }
}
