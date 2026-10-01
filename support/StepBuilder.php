<?php

namespace Zimudec\Wizard\Support;

/**
 * Fluent builder of a single wizard step.
 */
final class StepBuilder
{
    public function __construct(
        protected Builder $wizard,
        protected string $code,
        protected string $title,
        protected array $fields = [],
        protected array $rules = [],
        protected array $messages = [],
        protected ?\Closure $extraValidation = null,
        protected ?\Closure $commit = null,
        protected ?string $view = null,
        protected ?\Closure $handler = null,
        protected bool $revalidate = false,
        protected bool $revalidateOnSubmit = false,
        protected ?bool $keepData = null,
    ) {
    }

    /**
     * Fields of the step. Each field is an array with the keys:
     * name (required), type (default "text"), label, options (select),
     * attributes.
     */
    public function fields(array $fields): self
    {
        $this->fields = $fields;

        return $this;
    }

    public function rules(array $rules): self
    {
        $this->rules = $rules;

        return $this;
    }

    public function messages(array $messages): self
    {
        $this->messages = $messages;

        return $this;
    }

    public function extraValidation(\Closure $closure): self
    {
        $this->extraValidation = $closure;

        return $this;
    }

    public function commit(\Closure $closure): self
    {
        $this->commit = $closure;

        return $this;
    }

    public function view(string $partial): self
    {
        $this->view = $partial;

        return $this;
    }

    public function handler(\Closure $closure): self
    {
        $this->handler = $closure;

        return $this;
    }

    public function revalidate(bool $revalidate = true): self
    {
        $this->revalidate = $revalidate;

        return $this;
    }

    /**
     * Declares the revalidation of the previous steps for the submit only:
     * the visits do not pay the revalidation cost (no entry gate) and the
     * submit revalidates the previous steps with their rules.
     */
    public function revalidateOnSubmit(): self
    {
        $this->revalidateOnSubmit = true;

        return $this;
    }

    public function keepData(bool $keep = true): self
    {
        $this->keepData = $keep;

        return $this;
    }

    /**
     * Registers the built step in the wizard builder and returns it.
     */
    public function end(): Builder
    {
        $this->wizard->addStep([
            'code' => $this->code,
            'title' => $this->title,
            'fields' => $this->fields,
            'rules' => $this->rules,
            'messages' => $this->messages,
            'extra_validation' => $this->extraValidation,
            'commit' => $this->commit,
            'view' => $this->view,
            'handler' => $this->handler,
            'revalidate' => $this->revalidate,
            'revalidate_on_submit' => $this->revalidateOnSubmit,
            'keep_data' => $this->keepData,
        ]);

        return $this->wizard;
    }
}
