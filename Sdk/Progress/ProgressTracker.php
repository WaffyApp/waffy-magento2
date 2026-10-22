<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Progress;

use Waffy\Ecommerce\Contract\ProgressReporter;

/**
 * Announces each Waffy call to the platform's ProgressReporter, if it has one.
 *
 * Shared by the orchestrator and the token cache so a checkout reports one
 * continuous sequence of steps, whichever class makes the call.
 */
class ProgressTracker
{
    public function __construct(private readonly ?ProgressReporter $reporter = null)
    {
    }

    /**
     * Run one API call, announcing it to the ProgressReporter before and after.
     *
     * Failures are reported and then rethrown untouched — the reporter observes
     * the flow, it never changes it.
     *
     * @template T
     * @param callable():T $call
     * @return T
     */
    public function tracked(CheckoutStep $step, callable $call): mixed
    {
        $this->reporter?->report($step, StepStatus::Running, 0.0);

        $startedAt = microtime(true);

        try {
            $result = $call();
        } catch (\Throwable $e) {
            $this->reporter?->report($step, StepStatus::Failed, $this->elapsedMs($startedAt));
            throw $e;
        }

        $this->reporter?->report($step, StepStatus::Done, $this->elapsedMs($startedAt));

        return $result;
    }

    /** Announce a call that did NOT happen because the cache already had a token. */
    public function cached(CheckoutStep $step): void
    {
        $this->reporter?->report($step, StepStatus::Cached, 0.0);
    }

    private function elapsedMs(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 1);
    }
}
