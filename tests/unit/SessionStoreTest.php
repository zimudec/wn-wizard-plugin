<?php

namespace Zimudec\Wizard\Tests\Unit;

use Carbon\Carbon;
use Crypt;
use Zimudec\Wizard\Support\SessionStore;
use Zimudec\Wizard\Tests\BaseTestCase;

/**
 * Unit tests of the wizard session store: namespacing per alias, data/meta
 * separation, whitelist-safe persistence, sliding TTL and opt-in encryption.
 */
class SessionStoreTest extends BaseTestCase
{
    public function test_state_is_namespaced_per_alias()
    {
        $a = new SessionStore('zimudec.wizard.alpha');
        $b = new SessionStore('zimudec.wizard.beta');

        $a->saveStepData('step-1', ['field1' => 'value-a']);
        $a->setStepCurrent(1);

        $this->assertEquals([], $b->mergedData());
        $this->assertEquals(0, $b->stepCurrent());
        $this->assertEquals('value-a', $a->mergedData()['field1']);
    }

    public function test_data_and_meta_are_separated()
    {
        $store = new SessionStore('zimudec.wizard.alpha');

        $store->saveStepData('step-1', ['field1' => 'value']);
        $store->setStepCurrent(1);
        $store->touch();

        $state = $store->raw();
        $this->assertArrayHasKey('data', $state);
        $this->assertArrayHasKey('meta', $state);
        $this->assertEquals(['field1' => 'value'], $state['data']['step-1']);
        $this->assertEquals(1, $state['meta']['stepCurrent']);
        $this->assertArrayHasKey('lastActivity', $state['meta']);
    }

    /**
     * Bug B7 in 1.0.7: the view received the raw session (metadata included).
     * In 2.0 only the data is exposed; a form field colliding with an
     * internal key is treated as user data and does not corrupt the state.
     */
    public function test_meta_is_not_exposed_and_name_collisions_do_not_corrupt_the_state()
    {
        $store = new SessionStore('zimudec.wizard.alpha');

        $store->saveStepData('step-1', ['meta' => 'user-value', 'stepCurrent' => 'also-user']);
        $store->setStepCurrent(1);

        $merged = $store->mergedData();
        $this->assertEquals('user-value', $merged['meta']);
        $this->assertEquals('also-user', $merged['stepCurrent']);

        $state = $store->raw();
        $this->assertEquals(1, $state['meta']['stepCurrent']);
        $this->assertEquals(['meta' => 'user-value', 'stepCurrent' => 'also-user'], $state['data']['step-1']);
    }

    /**
     * Sliding TTL: activity refreshes the timer; after the TTL the next
     * access reports the state as expired; a null TTL disables expiration.
     */
    public function test_sliding_ttl()
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
        $store = new SessionStore('zimudec.wizard.alpha');

        $store->setStepCurrent(1);
        $store->touch();

        // Active use keeps the wizard alive.
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:29:00'));
        $this->assertFalse($store->isExpired(30));

        // Activity refreshes the timer.
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:40:00'));
        $store->touch();

        Carbon::setTestNow(Carbon::parse('2026-01-01 13:09:00'));
        $this->assertFalse($store->isExpired(30));

        // Beyond the TTL the state is expired.
        Carbon::setTestNow(Carbon::parse('2026-01-01 13:41:00'));
        $this->assertTrue($store->isExpired(30));

        // A null TTL disables expiration.
        $this->assertFalse($store->isExpired(null));

        Carbon::setTestNow();
    }

    public function test_encryption_is_opt_in_and_tolerant_of_plain_values()
    {
        // Default: OFF — values persist as-is.
        $plain = new SessionStore('zimudec.wizard.alpha');
        $plain->saveStepData('step-1', ['field1' => 'value']);
        $this->assertEquals('value', session('zimudec.wizard.alpha')['data']['step-1']['field1']);
        $this->assertEquals('value', $plain->mergedData()['field1']);

        session()->forget('zimudec.wizard.alpha');

        // Opt-in: data is encrypted at rest and decoded on read.
        $encrypted = new SessionStore('zimudec.wizard.alpha', true);
        $encrypted->saveStepData('step-1', ['field1' => 'secret', 'field2' => ['nested' => 1]]);

        $stored = session('zimudec.wizard.alpha')['data']['step-1'];
        $this->assertNotEquals('value', $stored['field1']);
        $this->assertNotEquals('value', Crypt::decryptString($stored['field1']));
        $this->assertEquals(['field1' => 'secret', 'field2' => ['nested' => 1]], $encrypted->mergedData());

        // Reading tolerates previously plain values.
        $state = session('zimudec.wizard.alpha');
        $state['data']['step-1']['legacy'] = 'plain-value';
        session(['zimudec.wizard.alpha' => $state]);

        $this->assertEquals('plain-value', $encrypted->stepData('step-1')['legacy']);
        $this->assertEquals(['nested' => 1], $encrypted->stepData('step-1')['field2']);
    }

    public function test_wipe_from_removes_data_of_later_steps_only()
    {
        $store = new SessionStore('zimudec.wizard.alpha');

        $store->saveStepData('step-1', ['field1' => 'one']);
        $store->saveStepData('step-2', ['field2' => 'two']);
        $store->saveStepData('step-3', ['field3' => 'three']);

        $store->wipeFrom('step-2');

        $this->assertEquals('one', $store->stepData('step-1')['field1']);
        $this->assertEquals('two', $store->stepData('step-2')['field2']);
        $this->assertEquals([], $store->stepData('step-3'));
    }

    /**
     * The wipe boundary is the CONFIG order of the steps, not the order in
     * which the data happened to be written: a middle step without rules
     * stores nothing, and a key-based lookup would silently skip the wipe
     * (privacy: the invalid data of the failing step would survive).
     */
    public function test_the_wipe_boundary_comes_from_the_step_order_when_the_previous_step_stored_no_data()
    {
        $store = new SessionStore('zimudec.wizard.alpha', false, ['step-1', 'step-2', 'step-3']);

        $store->saveStepData('step-1', ['field1' => 'one']);
        $store->saveStepData('step-3', ['field3' => 'three']);

        // step-2 stored nothing (no rules): the boundary is step-3 in the
        // config order, so the wipe must still reach step-3.
        $store->wipeFrom('step-2');

        $this->assertEquals('one', $store->stepData('step-1')['field1']);
        $this->assertEquals([], $store->stepData('step-3'));
    }

    /**
     * The narrow retention case: the wipe still removes the later steps, but
     * the ones the page declared as kept survive. It is what lets a flow hold
     * the data of one step (the rows of a dynamic grid) without holding the
     * personal data of the step after it, and without writing a copy of the
     * data somewhere else to keep it alive.
     */
    public function test_a_step_declared_as_kept_survives_the_wipe()
    {
        $store = new SessionStore('zimudec.wizard.alpha');

        $store->saveStepData('step-1', ['local' => 'centro']);
        $store->saveStepData('step-2', ['total' => 60]);
        $store->saveStepData('step-3', ['rut' => '12345678-5']);

        $store->wipeFrom('step-1', ['step-3']);

        $this->assertEquals('centro', $store->stepData('step-1')['local']);
        $this->assertEquals([], $store->stepData('step-2'));
        $this->assertEquals('12345678-5', $store->stepData('step-3')['rut']);
    }

    /**
     * The revalidation bounce wipes because the data is INVALID, not because
     * the user navigated back, so it passes no kept steps: keeping a failing
     * step's data would defeat the correction that triggered the bounce.
     */
    public function test_a_wipe_without_kept_steps_removes_them()
    {
        $store = new SessionStore('zimudec.wizard.alpha');

        $store->saveStepData('step-1', ['local' => 'centro']);
        $store->saveStepData('step-2', ['total' => 60]);

        $store->wipeFrom('step-1');

        $this->assertEquals('centro', $store->stepData('step-1')['local']);
        $this->assertEquals([], $store->stepData('step-2'));
    }

    /**
     * A kept code that is not a configured step cannot resurrect data, and
     * must not resurrect it under a key the wizard does not own either.
     */
    public function test_keeping_a_step_the_configuration_does_not_declare_is_inert()
    {
        $store = new SessionStore('zimudec.wizard.alpha', false, ['step-1', 'step-2']);

        $store->saveStepData('step-1', ['local' => 'centro']);
        $store->saveStepData('step-2', ['total' => 60]);

        $store->wipeFrom('step-1', ['step-9']);

        $this->assertEquals([], $store->stepData('step-2'));
        $this->assertArrayNotHasKey('step-9', $store->mergedData());
    }

    /**
     * The stored data is ordered by step code (the config order), so the
     * "later steps override earlier ones" semantics of mergedData() hold
     * regardless of the write order (a session API write out of order).
     */
    public function test_data_is_stored_in_step_order_so_later_steps_override_on_collision()
    {
        $store = new SessionStore('zimudec.wizard.alpha', false, ['step-1', 'step-2']);

        // Written first, but later in the flow.
        $store->saveStepData('step-2', ['field' => 'from-step-2']);
        $store->saveStepData('step-1', ['field' => 'from-step-1']);

        $this->assertEquals('from-step-2', $store->mergedData()['field']);
    }

    public function test_flush_removes_the_entire_state()
    {
        $store = new SessionStore('zimudec.wizard.alpha');
        $store->saveStepData('step-1', ['field1' => 'value']);

        $store->flush();

        $this->assertFalse($store->exists());
        $this->assertNull(session('zimudec.wizard.alpha'));
    }

    /**
     * The named keys leave the given step and the rest of that step stays.
     * This is the rename case: the old key name must be removable, and only
     * it.
     */
    public function test_forgetting_keys_removes_them_from_one_step_only()
    {
        $store = new SessionStore('zimudec.wizard.alpha', false, ['step-1', 'step-2']);

        $store->saveStepData('step-1', ['total' => 60, 'subtotal' => 60, 'code' => 'x']);
        $store->saveStepData('step-2', ['total' => 45]);

        $store->forgetStepKeys('step-1', ['total']);

        $this->assertArrayNotHasKey('total', $store->stepData('step-1'));
        $this->assertEquals(60, $store->stepData('step-1')['subtotal']);
        $this->assertEquals('x', $store->stepData('step-1')['code']);

        // The same key name in another step is a different key in a different
        // step: forgetting it in one must not take the other.
        $this->assertEquals(45, $store->stepData('step-2')['total']);
    }

    /**
     * Without a step code the keys leave every step: renaming a field rarely
     * comes with a reliable list of the steps that had written it.
     */
    public function test_forgetting_keys_without_a_step_removes_them_from_every_step()
    {
        $store = new SessionStore('zimudec.wizard.alpha', false, ['step-1', 'step-2', 'step-3']);

        $store->saveStepData('step-1', ['total' => 60, 'keep' => 1]);
        $store->saveStepData('step-2', ['total' => 45]);
        $store->saveStepData('step-3', ['keep' => 3]);

        $store->forgetStepKeys(null, ['total']);

        $this->assertArrayNotHasKey('total', $store->mergedData());
        $this->assertEquals(1, $store->stepData('step-1')['keep']);
        $this->assertEquals(3, $store->stepData('step-3')['keep']);
    }

    /**
     * A key that was never stored, and a step that stores nothing, are
     * no-ops. Forgetting is idempotent by nature: running it twice, or
     * running it against a state the user has not reached, must not throw.
     */
    public function test_forgetting_a_key_that_is_not_stored_is_inert()
    {
        $store = new SessionStore('zimudec.wizard.alpha', false, ['step-1', 'step-2']);

        $store->saveStepData('step-1', ['subtotal' => 60]);

        $store->forgetStepKeys('step-1', ['never-written']);
        $store->forgetStepKeys('step-2', ['subtotal']);

        $this->assertEquals(['subtotal' => 60], $store->stepData('step-1'));
        $this->assertEquals([], $store->stepData('step-2'));
    }

    /**
     * Forgetting leaves the rest of the state alone: the progress pointer
     * and the inactivity timer are not data, and forgetting data must not
     * send the user back to step 1.
     */
    public function test_forgetting_keys_preserves_the_metadata()
    {
        $store = new SessionStore('zimudec.wizard.alpha', false, ['step-1', 'step-2']);

        $store->saveStepData('step-1', ['total' => 60]);
        $store->setStepCurrent(2);
        $store->touch();

        $activity = $store->lastActivity();

        $store->forgetStepKeys(null, ['total']);

        $this->assertEquals(2, $store->stepCurrent());
        $this->assertEquals(
            $activity->getTimestamp(),
            $store->lastActivity()->getTimestamp()
        );
    }

    /**
     * A step left with no keys is dropped rather than kept as an empty
     * array, so a "forget everything a step had" call does not leave an
     * empty step behind that reads as a step the user completed.
     */
    public function test_a_step_left_empty_is_dropped()
    {
        $store = new SessionStore('zimudec.wizard.alpha', false, ['step-1', 'step-2']);

        $store->saveStepData('step-1', ['total' => 60]);
        $store->saveStepData('step-2', ['subtotal' => 45]);

        $store->forgetStepKeys('step-1', ['total']);

        $this->assertArrayNotHasKey('step-1', $store->data());
        $this->assertArrayHasKey('step-2', $store->data());
    }

    /**
     * Encrypted state: the keys are names, not values, so forgetting does
     * not have to read or rewrite any ciphertext.
     */
    public function test_forgetting_keys_works_on_encrypted_state()
    {
        $store = new SessionStore('zimudec.wizard.alpha', true, ['step-1', 'step-2']);

        $store->saveStepData('step-1', ['total' => 60, 'subtotal' => 60]);
        $store->saveStepData('step-2', ['subtotal' => 45]);

        $store->forgetStepKeys('step-1', ['total']);

        $this->assertArrayNotHasKey('total', $store->stepData('step-1'));
        $this->assertEquals(60, $store->stepData('step-1')['subtotal']);
        $this->assertEquals(45, $store->stepData('step-2')['subtotal']);
    }
}
