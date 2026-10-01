<?php

namespace Zimudec\Wizard\Support;

/**
 * Immutable configuration of a wizard instance, produced identically by the
 * three configuration levels (component properties, page builder, full
 * control). Normalizes every level to a single shape before the pipeline
 * (resolve → validate → store → advance) runs.
 */
final readonly class WizardConfig
{
    public function __construct(
        public array $steps,
        public string $finishUrl = '/',
        public ?int $ttl = null,
        public bool $wipeOnBack = true,
        public bool $retainData = false,
        public bool $encrypted = false,
        public array $keepSteps = [],
    ) {
        $declared = array_map(
            static fn ($step): string => $step->code,
            $steps
        );

        foreach ($keepSteps as $code) {
            if (!in_array($code, $declared, true)) {
                throw new ConfigurationException(__('zimudec.wizard::lang.errors.keep_step_unknown', [
                    'step' => $code,
                ]));
            }
        }
    }

    /**
     * Level 0: builds the configuration from component properties only.
     * Declared fields render as text inputs without validation rules (rules
     * are never inferred).
     */
    public static function fromProperties(array $properties): self
    {
        $codes = static::splitList((string) ($properties['steps'] ?? ''));
        $titles = static::splitList((string) ($properties['titles'] ?? ''));
        $fieldNames = static::splitList((string) ($properties['fields'] ?? ''));

        if ($codes === []) {
            throw new ConfigurationException(__('zimudec.wizard::lang.errors.steps_empty'));
        }

        $fields = array_map(
            static fn (string $name): FieldConfig => new FieldConfig(name: $name, label: $name),
            $fieldNames
        );

        return new self(
            steps: array_map(
                static fn (int $index, string $code): StepConfig => new StepConfig(
                    code: $code,
                    title: $titles[$index] ?? $code,
                    fields: $fields,
                ),
                array_keys($codes),
                $codes
            ),
            finishUrl: (string) ($properties['finishUrl'] ?? '/') ?: '/',
            ttl: static::parseTtl($properties['ttl'] ?? null),
            wipeOnBack: (bool) ($properties['wipeOnBack'] ?? true),
            retainData: (bool) ($properties['retainData'] ?? false),
            encrypted: (bool) ($properties['encrypted'] ?? false),
            keepSteps: static::splitList((string) ($properties['keepSteps'] ?? '')),
        );
    }

    /**
     * Levels 1 and 2: a declarative array produced by the page builder.
     * Steps may carry fields with an explicit type, validation rules and
     * messages, extra validation, a view and a per-step handler.
     */
    public static function fromArray(array $config): self
    {
        if (($config['steps'] ?? []) === []) {
            throw new ConfigurationException(__('zimudec.wizard::lang.errors.steps_empty'));
        }

        $steps = array_map(
            static fn (array $step): StepConfig => static::parseStep($step),
            $config['steps']
        );

        return new self(
            steps: $steps,
            finishUrl: (string) ($config['finishUrl'] ?? '/'),
            ttl: static::parseTtl($config['ttl'] ?? null),
            wipeOnBack: (bool) ($config['wipeOnBack'] ?? true),
            retainData: (bool) ($config['retainData'] ?? false),
            encrypted: (bool) ($config['encrypted'] ?? false),
            keepSteps: static::parseKeepSteps($config['keepSteps'] ?? []),
        );
    }

    protected static function parseStep(array $step): StepConfig
    {
        $code = (string) ($step['code'] ?? '');

        if ($code === '') {
            throw new ConfigurationException(__('zimudec.wizard::lang.errors.step_code_empty'));
        }

        $fields = array_map(
            static fn (array $field): FieldConfig => new FieldConfig(
                name: (string) ($field['name'] ?? ''),
                type: (string) ($field['type'] ?? 'text') ?: 'text',
                label: (string) ($field['label'] ?? ''),
                options: (array) ($field['options'] ?? []),
                attributes: (array) ($field['attributes'] ?? []),
            ),
            $step['fields'] ?? []
        );

        foreach ($fields as $field) {
            if ($field->name === '') {
                throw new ConfigurationException(__('zimudec.wizard::lang.errors.field_name_empty'));
            }
        }

        return new StepConfig(
            code: $code,
            title: (string) ($step['title'] ?? '') ?: $code,
            fields: $fields,
            rules: $step['rules'] ?? [],
            messages: $step['messages'] ?? [],
            extraValidation: $step['extra_validation'] ?? null,
            commit: $step['commit'] ?? null,
            view: $step['view'] ?? null,
            handler: $step['handler'] ?? null,
            revalidate: (bool) ($step['revalidate'] ?? false),
            revalidateOnSubmit: (bool) ($step['revalidate_on_submit'] ?? false),
            keepData: $step['keep_data'] ?? null,
        );
    }

    /**
     * Accepts the array the builder produces or the "|"-separated string of
     * the level 0 property. An empty declaration is no declaration: the
     * property default is "" and must not become a list holding one empty
     * step code.
     *
     * @return string[]
     */
    protected static function parseKeepSteps(mixed $keepSteps): array
    {
        if (is_string($keepSteps)) {
            return static::splitList($keepSteps);
        }

        return array_values(array_filter(
            array_map(static fn ($code): string => trim((string) $code), (array) $keepSteps),
            static fn (string $code): bool => $code !== ''
        ));
    }

    protected static function parseTtl(mixed $ttl): ?int
    {
        if ($ttl === null || $ttl === '') {
            return null;
        }

        return max(0, (int) $ttl);
    }

    /**
     * Splits a "|"-separated property value, trimming each entry. Public
     * because the builder accepts the same notation as the property.
     *
     * @return string[]
     */
    public static function splitList(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode('|', str_replace(',', '|', $value))),
            static fn (string $item): bool => $item !== ''
        ));
    }
}
