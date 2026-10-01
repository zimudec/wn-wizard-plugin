<?php

namespace Zimudec\Wizard\Support;

use Crypt;
use Winter\Storm\Support\Arr;

/**
 * Namespaced session store of a wizard instance.
 *
 * The state lives under a single session key ("zimudec.wizard.<page>.<alias>",
 * built by the component) so two wizards never share state, and separates the
 * user data ("data", per step, only whitelisted fields) from the internal
 * metadata ("meta": the current step, the last activity time and the finished
 * flag). Only the data reaches the views; a form field colliding with an
 * internal key cannot corrupt the state.
 *
 * The optional step codes (the config order) keep the stored data ordered by
 * step: the wipe boundaries and the merge order of mergedData() come from the
 * configuration, not from the write order (a step without rules stores
 * nothing, and session API writes can happen out of order).
 */
final class SessionStore
{
    public function __construct(
        protected readonly string $key,
        protected readonly bool $encrypted = false,
        protected readonly array $stepCodes = [],
    ) {
    }

    /**
     * Whether the wizard has state in the session.
     */
    public function exists(): bool
    {
        return session()->has($this->key);
    }

    /**
     * Full internal state, normalized to the {data, meta} shape.
     */
    public function raw(): array
    {
        $state = session()->get($this->key);

        return is_array($state)
            ? array_replace_recursive($this->freshState(), $state)
            : $this->freshState();
    }

    /**
     * Data stored for a single step, decoded.
     */
    public function stepData(string $stepCode): array
    {
        return array_map($this->unwrap(...), data_get($this->raw(), 'data.' . $stepCode, []));
    }

    /**
     * Data stored for every step (keyed by step code), decoded.
     */
    public function data(): array
    {
        return array_map(
            fn (array $stepData): array => array_map($this->unwrap(...), $stepData),
            data_get($this->raw(), 'data', [])
        );
    }

    /**
     * Internal metadata of the wizard (current step, last activity, finished flag).
     */
    public function meta(): array
    {
        return data_get($this->raw(), 'meta', []);
    }

    /**
     * Data of every step merged (later steps override earlier ones).
     */
    public function mergedData(): array
    {
        $data = array_values($this->data());

        return $data === [] ? [] : array_merge(...$data);
    }

    /**
     * Persists whitelisted data for a step. Values already stored for the
     * step are preserved for fields not present in the input.
     */
    public function saveStepData(string $stepCode, array $data): void
    {
        if ($data === []) {
            return;
        }

        $state = $this->raw();
        $state['data'] = $this->orderSteps(array_merge($state['data'] ?? [], [
            $stepCode => array_merge(
                data_get($state, 'data.' . $stepCode, []),
                $this->encrypted ? array_map($this->wrap(...), $data) : $data
            ),
        ]));

        session([$this->key => $state]);
    }

    /**
     * Current step position (the last validated step index; 0 for a fresh wizard).
     */
    public function stepCurrent(): int
    {
        return (int) data_get($this->meta(), 'stepCurrent', 0);
    }

    /**
     * Marks the given position as validated (the maximum reachable step).
     */
    public function setStepCurrent(int $position): void
    {
        $state = $this->raw();
        $state['meta'] = array_merge($this->meta(), ['stepCurrent' => max(0, $position)]);

        session([$this->key => $state]);
    }

    /**
     * Refreshes the sliding inactivity timer.
     */
    public function touch(): void
    {
        $state = $this->raw();
        $state['meta'] = array_merge($this->meta(), ['lastActivity' => now()->getTimestamp()]);

        session([$this->key => $state]);
    }

    /**
     * Last interaction with the wizard.
     */
    public function lastActivity(): ?\Winter\Storm\Argon\Argon
    {
        $timestamp = data_get($this->meta(), 'lastActivity');

        return $timestamp ? \Winter\Storm\Argon\Argon::createFromTimestamp($timestamp) : null;
    }

    /**
     * Whether the wizard state expired by inactivity for the given TTL
     * (in minutes). A null TTL disables expiration.
     */
    public function isExpired(?int $ttl): bool
    {
        if ($ttl === null || !$this->exists()) {
            return false;
        }

        $lastActivity = data_get($this->meta(), 'lastActivity');

        return $lastActivity !== null && now()->getTimestamp() > $lastActivity + ($ttl * 60);
    }

    /**
     * Marks the wizard as finished: the state survives the final render and
     * is cleared on the next interaction (framework-native deferred deletion).
     */
    public function markFinished(): void
    {
        $state = $this->raw();
        $state['meta'] = array_merge($this->meta(), ['finished' => true]);

        session([$this->key => $state]);
    }

    /**
     * Whether the wizard was finished (data must be cleared and the flow restarted).
     */
    public function isFinished(): bool
    {
        return (bool) data_get($this->meta(), 'finished', false);
    }

    /**
     * Removes the state of every step after the given one (wipe-on-back with
     * the privacy default; with retainData the caller skips this). The
     * boundary is resolved in the CONFIG order of the steps, not in the order
     * the data was written: a middle step without rules stores nothing, and a
     * key-based lookup would silently skip the wipe.
     *
     * $keepSteps names the steps whose data survives the wipe. It is the
     * narrow retention case, for a page that needs the data of one later step
     * (the rows of a grid it re-reads) without holding the personal data of
     * the step after it. A code outside the configured steps is inert.
     */
    public function wipeFrom(string $stepCode, array $keepSteps = []): void
    {
        $codes = $this->stepCodes !== []
            ? $this->stepCodes
            : array_keys(data_get($this->raw(), 'data', []));
        $position = array_search($stepCode, $codes, true);

        if ($position === false) {
            return;
        }

        $survives = array_slice($codes, 0, $position + 1);

        if ($keepSteps !== []) {
            $survives = array_merge($survives, array_intersect($keepSteps, $codes));
        }

        $state = $this->raw();
        $state['data'] = array_intersect_key($state['data'] ?? [], array_flip($survives));

        session([$this->key => $state]);
    }

    /**
     * Removes the entire wizard state.
     */
    public function flush(): void
    {
        session()->forget($this->key);
    }

    /**
     * Removes named keys from one step, or from every step when $stepCode is
     * null.
     *
     * The counterpart of saveStepData()'s merge, and the only way to drop a
     * key: saveStepData() preserves whatever the incoming array does not name,
     * so a field that changes name leaves the old key stored for the lifetime
     * of the wizard, where the merged view can still return it — a value no
     * step wrote, indistinguishable from a live one.
     *
     * Keys are names, not values, so this does not read or rewrite any
     * ciphertext: it works the same on an encrypted state. A key that was
     * never stored, and a step that stores nothing, are no-ops: forgetting is
     * idempotent by nature, and the session is routinely behind the code that
     * asks. The progress pointer and the inactivity timer are metadata, not
     * data, and are never touched. A step left with no keys is dropped rather
     * than kept as an empty array, so "forget everything this step had" does
     * not leave behind a step that reads as one the user completed.
     *
     * @param string|null $stepCode the step to forget from; null forgets from all of them
     * @param array $keys the top-level keys to remove
     */
    public function forgetStepKeys(?string $stepCode, array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $state = $this->raw();
        $data = $state['data'] ?? [];

        if ($stepCode !== null) {
            if (!array_key_exists($stepCode, $data)) {
                return;
            }

            $data[$stepCode] = array_diff_key($data[$stepCode] ?? [], array_flip($keys));
            $data = $this->orderSteps(array_filter($data, static fn ($values) => $values !== []));
        } else {
            foreach ($data as $code => $values) {
                $data[$code] = array_diff_key($values, array_flip($keys));
            }

            $data = array_filter($data, static fn ($values) => $values !== []);
        }

        $state['data'] = $data;

        session([$this->key => $state]);
    }

    /**
     * Resets the state to a fresh wizard (no data, step 1, finished flag off)
     * without destroying the session entry.
     */
    public function reset(): void
    {
        session([$this->key => $this->freshState()]);
    }

    /**
     * Fresh state of the wizard.
     */
    public function freshState(): array
    {
        return ['data' => [], 'meta' => []];
    }

    /**
     * Orders the stored data by the config order of the steps (unknown step
     * codes keep their relative position after the known ones, defensively).
     */
    protected function orderSteps(array $data): array
    {
        if ($this->stepCodes === []) {
            return $data;
        }

        $ordered = [];

        foreach ($this->stepCodes as $code) {
            if (array_key_exists($code, $data)) {
                $ordered[$code] = $data[$code];
            }
        }

        foreach ($data as $code => $values) {
            if (!array_key_exists($code, $ordered)) {
                $ordered[$code] = $values;
            }
        }

        return $ordered;
    }

    /**
     * Encrypts a value for storage when the wizard is configured to encrypt.
     * Uploaded files are dropped instead of serialized.
     */
    protected function wrap(mixed $value): mixed
    {
        if ($value instanceof \Illuminate\Http\UploadedFile) {
            return null;
        }

        return Crypt::encryptString(serialize($value));
    }

    /**
     * Decrypts a stored value. Values written before the encryption was
     * enabled (plain values) are tolerated and returned as they are.
     */
    protected function unwrap(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        try {
            $decrypted = Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value;
        }

        $unserialized = @unserialize($decrypted, ['allowed_classes' => false]);
        if ($unserialized === false && $decrypted !== 'b:0;') {
            return $value;
        }

        return $unserialized;
    }
}
