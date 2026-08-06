<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Contract;

use Waffy\Ecommerce\Progress\CheckoutStep;
use Waffy\Ecommerce\Progress\StepStatus;

/**
 * Observation seam for the checkout flow.
 *
 * The orchestrator calls this before and after every API call, so a platform can
 * log the sequence with timings, drive a live progress bar, or both. It is purely
 * an observer: implementations must not throw and must not alter the flow — a
 * broken progress display is never a reason to fail a payment.
 *
 * Passing no reporter (the default) disables instrumentation entirely, so the
 * orchestrator stays usable in contexts with nothing to report to (cron, CLI).
 */
interface ProgressReporter
{
    /**
     * @param CheckoutStep $step      Which call.
     * @param StepStatus   $status    Running when it starts; Done / Cached / Failed when it settles.
     * @param float        $elapsedMs Milliseconds the call took. Zero for Running,
     *                                and effectively zero for Cached (no HTTP happened).
     */
    public function report(CheckoutStep $step, StepStatus $status, float $elapsedMs): void;
}
