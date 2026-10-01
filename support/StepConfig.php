<?php

namespace Zimudec\Wizard\Support;

use Closure;

/**
 * Declarative definition of a single wizard step.
 */
final readonly class StepConfig
{
    public function __construct(
        public string $code,
        public string $title = '',
        public array $fields = [],
        public array $rules = [],
        public array $messages = [],
        public ?Closure $extraValidation = null,
        public ?Closure $commit = null,
        public ?string $view = null,
        public ?Closure $handler = null,
        public bool $revalidate = false,
        public bool $revalidateOnSubmit = false,
        public ?bool $keepData = null,
    ) {
    }

    /**
     * Field names of the step; also the whitelist of persisted fields.
     */
    public function fieldNames(): array
    {
        return array_map(static fn (FieldConfig $field): string => $field->name, $this->fields);
    }

    /**
     * Validation rules keyed by field name. Fields declared without rules do
     * not validate (no rules are ever inferred), but they are still part of
     * the persistence whitelist.
     */
    public function ruleKeys(): array
    {
        return array_keys($this->rules);
    }
}
