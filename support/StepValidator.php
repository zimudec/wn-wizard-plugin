<?php

namespace Zimudec\Wizard\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationRuleParser;
use Validator;
use ValidationException;

/**
 * Validation pipeline of a wizard step: base rules + custom messages, extra
 * validation with access to the accumulated data, and the commit hook. Errors
 * reach the frontend through ValidationException, which the framework turns
 * into the AJAX error fields format Snowboard expects.
 */
final class StepValidator
{
    /** Tells "no such key" apart from "that key, set to null". */
    private const MISSING = "\0zimudec.wizard.missing\0";

    /**
     * Persists only what the step declares (its rule keys and its field
     * names). An allowed key covers everything under it, which is what keeps a
     * rule declared on an array parent from losing its contents — but only
     * while no rule is declared inside that parent (see allowedKeys()).
     */
    public function filterInput(array $input, StepConfig $step): array
    {
        $input = static::withoutFiles($input);
        $whitelisted = [];

        foreach (array_merge(static::allowedKeys($step, $input), $step->fieldNames()) as $key) {
            $value = data_get($input, $key, self::MISSING);

            if ($value !== self::MISSING) {
                Arr::set($whitelisted, $key, $value);
            }
        }

        return $whitelisted;
    }

    /**
     * The keys the rules cover. Without wildcards the declaration is the key
     * list itself and the parser never runs; a wildcard needs the concrete
     * keys behind it, and those come from the expansion the validator already
     * computes, so the whitelist cannot drift from the validation.
     *
     * A rule declared on a parent covers the parent's contents ONLY while
     * nothing is declared inside it. As soon as a rule names something under
     * that parent, the rules below define the allowed keys, because an allowed
     * key covers everything beneath it and the siblings no rule mentions would
     * otherwise ride along unvalidated: with `address` plus `address.lines.*`,
     * an `address.city` the rules never saw would be persisted.
     */
    protected static function allowedKeys(StepConfig $step, array $input): array
    {
        $keys = array_keys($step->rules);

        if (!static::hasWildcard($keys)) {
            return $keys;
        }

        $exploded = array_keys((new ValidationRuleParser($input))->explode($step->rules)->rules);
        $ancestors = static::ancestorsOf($keys);
        $allowed = array_values(array_diff($exploded, $ancestors));

        // A declared container that arrived empty is kept as an empty array
        // rather than dropped, so reading it back yields [] and not null.
        foreach ($ancestors as $ancestor) {
            if (data_get($input, $ancestor) === []) {
                $allowed[] = $ancestor;
            }
        }

        return array_values(array_unique($allowed));
    }

    /**
     * @param  string[]  $keys
     */
    protected static function hasWildcard(array $keys): bool
    {
        foreach ($keys as $key) {
            if (Str::contains($key, '*')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rule keys that another rule declares something under.
     *
     * @param  string[]  $keys
     * @return string[]
     */
    protected static function ancestorsOf(array $keys): array
    {
        $ancestors = [];

        foreach ($keys as $key) {
            if (Str::contains($key, '*')) {
                continue;
            }

            foreach ($keys as $other) {
                if ($other !== $key && Str::startsWith($other, $key . '.')) {
                    $ancestors[] = $key;

                    break;
                }
            }
        }

        return $ancestors;
    }

    /** Files are never serialized to the session. */
    protected static function withoutFiles(array $input): array
    {
        foreach ($input as $key => $value) {
            if ($value instanceof \Illuminate\Http\UploadedFile) {
                unset($input[$key]);

                continue;
            }

            if (is_array($value)) {
                $input[$key] = static::withoutFiles($value);
            }
        }

        return $input;
    }

    /**
     * Base rules only, returning the field errors instead of throwing: the
     * revalidation of the previous steps is skipped when the current step has
     * already failed.
     */
    public function validateBase(StepConfig $step, array $input): array
    {
        $validator = Validator::make($input, $step->rules, $step->messages);

        return $validator->fails() ? $validator->errors()->messages() : [];
    }

    /**
     * Base errors plus the step's extra validation, in a single
     * ValidationException. The closures run on a fresh validator because the
     * framework clears the message bag on every pass, which is also why the
     * base errors have to be seeded inside the after hook. Returns any derived
     * data to persist.
     */
    public function validateExtra(
        StepConfig $step,
        array $input,
        array $accumulated = [],
        array $baseErrors = []
    ): array {
        $validator = Validator::make([], []);

        $derived = [];

        $validator->after(function ($validator) use ($step, $input, &$derived, $accumulated, $baseErrors) {
            foreach ($baseErrors as $field => $messages) {
                foreach ((array) $messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }

            if ($step->extraValidation !== null) {
                $result = ($step->extraValidation)($validator, array_merge($accumulated, $input, $derived));

                if (is_array($result)) {
                    $derived = array_merge($derived, $result);
                }
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $derived;
    }

    /**
     * Base rules and extra validation together, as used when revalidating a
     * previous step. Returns any derived data to persist.
     */
    public function validate(StepConfig $step, array $input, array $accumulated = []): array
    {
        $validator = Validator::make($input, $step->rules, $step->messages);

        $derived = [];

        if ($step->extraValidation !== null) {
            $validator->after(function ($validator) use ($step, $input, &$derived, $accumulated) {
                $result = ($step->extraValidation)($validator, array_merge($accumulated, $input, $derived));

                if (is_array($result)) {
                    $derived = array_merge($derived, $result);
                }
            });
        }

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $derived;
    }

    /** Runs once per successful submit; a ValidationException blocks the advance. */
    public function commit(StepConfig $step, array $input, array $accumulated = []): void
    {
        if ($step->commit === null) {
            return;
        }

        ($step->commit)($input, $accumulated);
    }
}
