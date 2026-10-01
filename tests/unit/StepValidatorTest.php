<?php

namespace Zimudec\Wizard\Tests\Unit;

use Illuminate\Http\UploadedFile;
use Zimudec\Wizard\Support\FieldConfig;
use Zimudec\Wizard\Support\StepConfig;
use Zimudec\Wizard\Support\StepValidator;
use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * Unit tests of the validation pipeline helpers: input whitelisting (only
 * the fields declared in the step rules persist) and file discarding
 * (bug B2 in 1.0.7 serialized the whole request, files included).
 */
class StepValidatorTest extends BaseTestCase
{
    public function test_whitelist_keeps_only_fields_declared_in_the_rules()
    {
        $step = new StepConfig(
            code: 'step-1',
            rules: ['field1' => 'required'],
        );

        $validator = new StepValidator();

        $filtered = $validator->filterInput([
            'field1' => 'kept',
            'unknown' => 'discarded',
            '_token' => 'discarded',
        ], $step);

        $this->assertEquals(['field1' => 'kept'], $filtered);
    }

    /**
     * A wildcard rule is a perfectly valid Laravel declaration, and the
     * validator expands it, but the whitelist used to match keys exactly, so
     * the whole nested array was dropped after validation had already passed:
     * the submit looked like it worked and the data was gone.
     *
     * Only the keys the rules reach are persisted: the whitelist widens to
     * what the developer declared, it is never opened to the request.
     */
    public function test_a_wildcard_rule_persists_the_nested_input()
    {
        $step = new StepConfig(
            code: 'step-1',
            rules: ['items.*.quantity' => 'required|integer'],
        );

        $validator = new StepValidator();

        $filtered = $validator->filterInput([
            'items' => [
                ['quantity' => 2, 'sku' => 'A'],
                ['quantity' => 5, 'sku' => 'B'],
            ],
            '_token' => 'discarded',
        ], $step);

        $this->assertEquals([['quantity' => 2], ['quantity' => 5]], $filtered['items']);
    }

    /**
     * The wildcard whitelist was only ever exercised with the two or three row
     * arrays hard-coded in the tests below, which hides the class of failure
     * that actually bit the shop port: a grid whose row count is decided by
     * the user, where the whole nested payload vanished after validation had
     * already passed. The row count is data, not a fixture constant, so the
     * test builds N rows and asserts every one of them survives.
     */
    public function test_a_wildcard_rule_persists_every_row_of_a_grid_of_any_size()
    {
        $step = new StepConfig(
            code: 'beneficiaries',
            rules: ['rut' => 'array', 'rut.*' => 'required|digits:8'],
        );

        $validator = new StepValidator();

        foreach ([1, 2, 5, 17, 60] as $rows) {
            $input = [];

            for ($index = 0; $index < $rows; $index++) {
                $input['rut'][$index] = [
                    'value' => str_pad((string) ($index + 1), 8, '0', STR_PAD_LEFT),
                    'name' => 'Person ' . $index,
                ];
            }

            $input['_token'] = 'discarded';

            $filtered = $validator->filterInput($input, $step);

            $this->assertCount($rows, $filtered['rut'], "rows: {$rows}");

            for ($index = 0; $index < $rows; $index++) {
                $this->assertSame(
                    str_pad((string) ($index + 1), 8, '0', STR_PAD_LEFT),
                    $filtered['rut'][$index]['value'],
                    "rows: {$rows}, row: {$index}"
                );
                $this->assertSame('Person ' . $index, $filtered['rut'][$index]['name']);
            }
        }
    }

    /**
     * Without a parent rule, a rule on a nested key covers ONLY that key, at
     * any grid size: the crafted siblings inside each row are dropped and the
     * rows themselves all survive. This is the shape that keeps the whitelist
     * narrow, and the row count is data, not a fixture constant.
     */
    public function test_a_nested_wildcard_rule_drops_the_siblings_it_does_not_cover_at_any_grid_size()
    {
        $step = new StepConfig(
            code: 'beneficiaries',
            rules: ['rut.*.name' => 'required'],
        );

        $validator = new StepValidator();

        foreach ([1, 2, 5, 17, 60] as $rows) {
            $input = [];

            for ($index = 0; $index < $rows; $index++) {
                $input['rut'][$index] = [
                    'name' => 'Person ' . $index,
                    'admin' => 'crafted',
                    'note' => 'not declared',
                ];
            }

            $filtered = $validator->filterInput($input, $step);

            $this->assertCount($rows, $filtered['rut'], "rows: {$rows}");

            for ($index = 0; $index < $rows; $index++) {
                $this->assertSame(['name' => 'Person ' . $index], $filtered['rut'][$index]);
            }
        }
    }

    /**
     * The parent rule no longer widens the whitelist to the whole subtree once
     * something IS declared inside it (round 42, finding 41.4). This is the
     * shape the Laravel wildcard docs recommend and the one the shop page
     * uses, and it was the way unvalidated keys reached the session: `rut`
     * became an allowed key, an allowed key covers everything beneath it, so
     * `admin` and `note` were persisted at every row even though no rule
     * mentioned them.
     */
    public function test_a_parent_rule_does_not_widen_the_whitelist_past_the_rules_declared_below_it()
    {
        $step = new StepConfig(
            code: 'beneficiaries',
            rules: ['rut' => 'array', 'rut.*.name' => 'required'],
        );

        $validator = new StepValidator();

        $filtered = $validator->filterInput([
            'rut' => [
                ['name' => 'Jane', 'admin' => 'crafted'],
                ['name' => 'John', 'admin' => 'crafted'],
            ],
        ], $step);

        $this->assertSame(
            [['name' => 'Jane'], ['name' => 'John']],
            $filtered['rut']
        );
    }

    /**
     * The companion of the rule above, and the reason it is not a black hole:
     * with NOTHING declared inside the parent, the parent rule still covers
     * the whole subtree. Otherwise a step whose only rule is `array` on the
     * parent would validate its contents and then persist none of them.
     */
    public function test_a_parent_rule_alone_still_covers_the_whole_subtree()
    {
        $step = new StepConfig(
            code: 'beneficiaries',
            rules: ['rut' => 'array'],
        );

        $validator = new StepValidator();

        $filtered = $validator->filterInput([
            'rut' => [['name' => 'Jane', 'admin' => 'crafted']],
        ], $step);

        $this->assertSame('crafted', $filtered['rut'][0]['admin']);
    }

    /**
     * A rule on a dotted path used to keep the siblings nested below that
     * path. It no longer does, and this is the same decision as the rule
     * above seen from the other side: `address.city` is not a key any rule
     * names, and the promise is that undeclared keys are not persisted. The
     * declared `address.lines.*` keys still round-trip.
     */
    public function test_a_dotted_rule_keeps_what_is_nested_under_it_without_any_wildcard()
    {
        $step = new StepConfig(
            code: 'step-1',
            rules: ['address' => 'required', 'address.lines.*' => 'required'],
        );

        $validator = new StepValidator();

        $filtered = $validator->filterInput([
            'address' => ['city' => 'Rosario', 'lines' => ['1 Main']],
            'unrelated' => 'discarded',
        ], $step);

        $this->assertSame(
            ['lines' => ['1 Main']],
            $filtered['address']
        );
    }

    /**
     * A row count above the small numbers the other tests use is the real
     * regression guard, but the keys must stay CONTIGUOUS: a grid that submits
     * rows 0, 1 and 3 must not have row 2 materialize as a null hole in the
     * stored array, which is what a reindexed whitelist would produce.
     */
    public function test_a_wildcard_rule_does_not_invent_rows_the_user_did_not_submit()
    {
        $step = new StepConfig(
            code: 'beneficiaries',
            rules: ['rut' => 'array', 'rut.*' => 'required'],
        );

        $validator = new StepValidator();

        $filtered = $validator->filterInput([
            'rut' => [0 => ['a'], 1 => ['b'], 3 => ['d']],
        ], $step);

        $this->assertSame([0, 1, 3], array_keys($filtered['rut']));
    }

    /**
     * The whitelist is the set of attributes the rules actually reach, so a
     * rule on a nested concrete key keeps the siblings it does not cover out.
     */
    public function test_a_nested_rule_does_not_persist_sibling_keys_it_does_not_cover()
    {
        $step = new StepConfig(
            code: 'step-1',
            rules: ['items.*.quantity' => 'required|integer'],
        );

        $validator = new StepValidator();

        $filtered = $validator->filterInput([
            'items' => [['quantity' => 2, 'admin' => 'crafted']],
        ], $step);

        $this->assertEquals(['quantity' => 2], $filtered['items'][0]);
    }

    /**
     * Regression guard: declaring the parent array rule alongside the nested
     * one already worked (the parent matched exactly), and it must keep
     * working. It is also the case that a naive wildcard-only implementation
     * would break, because the dotted key of the parent never appears in the
     * flattened input.
     */
    public function test_a_rule_on_an_array_parent_keeps_its_contents()
    {
        $step = new StepConfig(
            code: 'step-1',
            rules: ['beneficiary' => 'required|array'],
        );

        $validator = new StepValidator();

        $filtered = $validator->filterInput([
            'beneficiary' => [['rut' => '1-9'], ['rut' => '2-8']],
        ], $step);

        $this->assertEquals([['rut' => '1-9'], ['rut' => '2-8']], $filtered['beneficiary']);
    }

    /**
     * The expansion of the validator handles nested wildcards and the
     * combination with a parent array rule without extra code.
     */
    public function test_nested_wildcards_and_a_parent_array_rule_are_supported()
    {
        $step = new StepConfig(
            code: 'step-1',
            rules: ['groups' => 'array', 'groups.*.members.*.email' => 'required|email'],
        );

        $validator = new StepValidator();

        $filtered = $validator->filterInput([
            'groups' => [
                ['members' => [['email' => 'a@example.com'], ['email' => 'b@example.com']]],
            ],
        ], $step);

        $this->assertEquals('a@example.com', $filtered['groups'][0]['members'][0]['email']);
        $this->assertEquals('b@example.com', $filtered['groups'][0]['members'][1]['email']);
    }

    /**
     * A step with a wildcard rule that receives nothing — the classic "add
     * items" form where the user added no rows — persists nothing and, more
     * importantly, does not fail. A form the user filled in correctly must
     * never turn into a server error.
     */
    public function test_a_wildcard_rule_with_no_submitted_data_persists_nothing_and_does_not_fail()
    {
        $step = new StepConfig(
            code: 'step-1',
            rules: ['items' => 'array', 'items.*.quantity' => 'required|integer'],
        );

        $validator = new StepValidator();

        $this->assertEquals([], $validator->filterInput(['_token' => 'x'], $step));
    }

    /**
     * Declaring a field without a rule persists it (persistence and
     * validation are orthogonal), and it is never validated: no rule is
     * inferred, and an empty value is accepted.
     */
    public function test_a_declared_field_without_rules_persists_its_value()
    {
        $step = new StepConfig(
            code: 'step-1',
            rules: [],
            fields: [new FieldConfig('name', 'Name')],
        );

        $validator = new StepValidator();

        $filtered = $validator->filterInput([
            'name' => 'typed by the user',
            'unknown' => 'discarded',
        ], $step);

        $this->assertEquals(['name' => 'typed by the user'], $filtered);
    }

    /**
     * A key that arrives empty is not a misconfiguration: a step may
     * legitimately submit an empty array, and there is nothing to persist
     * for it either way. The parent key is declared, so it is kept as an
     * empty array rather than dropped.
     */
    public function test_an_empty_array_does_not_trip_the_wildcard_guard()
    {
        $step = new StepConfig(
            code: 'step-1',
            rules: ['items' => 'array', 'items.*.quantity' => 'required|integer'],
        );

        $validator = new StepValidator();

        $this->assertEquals(['items' => []], $validator->filterInput(['items' => []], $step));
    }

    /**
     * The same, with only the wildcard declared: the parent never arrives as
     * a rule, so there is nothing to keep and nothing to complain about.
     */
    public function test_an_empty_array_with_only_the_wildcard_declared_persists_nothing()
    {
        $step = new StepConfig(
            code: 'step-1',
            rules: ['items.*.quantity' => 'required|integer'],
        );

        $validator = new StepValidator();

        $this->assertEquals([], $validator->filterInput(['items' => []], $step));
    }

    /**
     * A field the user cleared is not a field that is missing: an empty
     * value persists, so returning to the step shows the field empty instead
     * of restoring the value that was there before.
     */
    public function test_a_field_submitted_empty_persists_as_empty()
    {
        $step = new StepConfig(
            code: 'step-1',
            rules: ['field1' => 'nullable', 'address' => 'array', 'address.city' => 'nullable'],
        );

        $validator = new StepValidator();

        $filtered = $validator->filterInput([
            'field1' => null,
            'address' => ['city' => null],
        ], $step);

        $this->assertArrayHasKey('field1', $filtered);
        $this->assertNull($filtered['field1']);
        $this->assertArrayHasKey('city', $filtered['address']);
        $this->assertNull($filtered['address']['city']);
    }

    public function test_uploaded_files_are_never_whitelisted()
    {
        $step = new StepConfig(
            code: 'step-1',
            rules: ['field1' => 'required', 'attachment' => 'required'],
        );

        $validator = new StepValidator();

        $filtered = $validator->filterInput([
            'field1' => 'kept',
            'attachment' => UploadedFile::fake()->create('doc.pdf', 10),
        ], $step);

        $this->assertEquals(['field1' => 'kept'], $filtered);
    }
}
