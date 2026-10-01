<?php

namespace Zimudec\Wizard\Support;

/**
 * Declarative definition of a single wizard field.
 */
final readonly class FieldConfig
{
    public function __construct(
        public string $name,
        public string $type = 'text',
        public string $label = '',
        public array $options = [],
        public array $attributes = [],
    ) {
    }
}
