<?php

namespace Zimudec\Wizard\Support;

use Cms\Classes\Page;

/**
 * Maps the URL step parameter to the configured steps and builds the
 * navigation URLs (previous/next/finish) from the page URL.
 */
final readonly class StepResolver
{
    public function __construct(
        protected WizardConfig $config,
        protected ?object $page = null,
    ) {
    }

    public function count(): int
    {
        return count($this->config->steps);
    }

    public function step(int $position): StepConfig
    {
        return $this->config->steps[$position];
    }

    /**
     * Position of a step by its URL code; null when the code matches no step.
     */
    public function position(?string $code): ?int
    {
        if ($code === null || $code === '') {
            return null;
        }

        foreach ($this->config->steps as $position => $step) {
            if ($step->code === $code) {
                return $position;
            }
        }

        return null;
    }

    /**
     * Whether the given position is the last step of the wizard.
     */
    public function isLast(int $position): bool
    {
        return $position === $this->count() - 1;
    }

    /**
     * URL of the step at the given position.
     */
    public function urlFor(int $position): string
    {
        $step = $this->config->steps[$position] ?? null;

        if ($step === null || $this->page === null) {
            return url(($step->code ?? '') !== '' ? $step->code : '');
        }

        return \Cms\Classes\Page::url($this->page->fileName, ['step' => $step->code]);
    }
    /**
     * URL of the next step, or null when the position is the last one.
     */
    public function nextUrl(int $position): ?string
    {
        return isset($this->config->steps[$position + 1])
            ? $this->urlFor($position + 1)
            : null;
    }

    /**
     * URL of the previous step, or null when the position is the first one.
     */
    public function prevUrl(int $position): ?string
    {
        return $position > 0
            ? $this->urlFor($position - 1)
            : null;
    }
}
