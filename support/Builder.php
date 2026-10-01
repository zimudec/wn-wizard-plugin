<?php

namespace Zimudec\Wizard\Support;

/**
 * Declarative builder of a wizard configuration (levels 1 and 2). The page
 * declares the steps of the wizard with fields, validation rules, messages
 * and hooks without writing any request handler; the builder normalizes
 * everything into the same WizardConfig the component properties produce.
 */
final class Builder
{
    /**
     * @var array<int, array>
     */
    protected array $steps = [];

    /**
     * Wizard-wide settings. Null means "the page did not set it", so the
     * component properties can provide it: a setting is never silently
     * unreachable because the page declared its steps with define().
     */
    protected ?string $finishUrl = null;

    protected ?int $ttl = null;

    protected ?bool $wipeOnBack = null;

    protected ?bool $retainData = null;

    protected ?bool $encrypted = null;

    /**
     * @var array<int, string>|null
     */
    protected ?array $keepSteps = null;

    /**
     * Where the finish action lands when the last step completes
     * (the same configuration as the level 0 property).
     */
    public function finishUrl(string $url): self
    {
        $this->finishUrl = $url;

        return $this;
    }

    /**
     * Inactivity timeout in minutes. 0 disables the expiration; omitted, the
     * global `zimudec.wizard::ttl` applies.
     */
    public function ttl(int $minutes): self
    {
        $this->ttl = max(0, $minutes);

        return $this;
    }

    /**
     * Going back to a previous step clears the data of the later steps
     * (the default). Pass false to keep it.
     */
    public function wipeOnBack(bool $enabled = true): self
    {
        $this->wipeOnBack = $enabled;

        return $this;
    }

    /**
     * Keeps the data of the later steps when the user goes back, and makes the
     * completed steps clickable in the step indicator.
     */
    public function retainData(bool $enabled = true): self
    {
        $this->retainData = $enabled;

        return $this;
    }

    /**
     * Encrypts the data persisted in the session (opt-in).
     */
    public function encrypted(bool $enabled = true): self
    {
        $this->encrypted = $enabled;

        return $this;
    }

    /**
     * Steps whose data survives a back navigation. Going back still clears
     * every other later step, and the completed steps stay non-clickable: this
     * keeps the data of a step the flow needs (the rows of a grid it re-reads)
     * without holding the personal data of the step after it, and without
     * writing a copy of the data elsewhere to keep it alive.
     *
     * Accepts an array of step codes or a "|"-separated string, like the
     * level 0 property. Ignored while retainData is on, which already keeps
     * every later step.
     *
     * @param array<int, string>|string $codes
     */
    public function keepSteps(array|string $codes): self
    {
        $this->keepSteps = is_string($codes)
            ? WizardConfig::splitList($codes)
            : array_values($codes);

        return $this;
    }

    /**
     * Declares a step and returns its builder.
     */
    public function step(string $code, string $title = ''): StepBuilder
    {
        return new StepBuilder($this, $code, $title);
    }

    /**
     * Internal: registers a built step.
     */
    public function addStep(array $step): void
    {
        $this->steps[] = $step;
    }

    /**
     * Produces the declarative array consumed by WizardConfig::fromArray().
     * Only the settings the page actually declared are emitted, so the caller
     * can tell them apart from the ones left to the component properties.
     */
    public function toArray(): array
    {
        $config = ['steps' => $this->steps];

        $declared = [
            'finishUrl' => $this->finishUrl,
            'ttl' => $this->ttl,
            'wipeOnBack' => $this->wipeOnBack,
            'retainData' => $this->retainData,
            'encrypted' => $this->encrypted,
            'keepSteps' => $this->keepSteps,
        ];

        foreach ($declared as $key => $value) {
            if ($value !== null) {
                $config[$key] = $value;
            }
        }

        return $config;
    }
}
