<?php

declare(strict_types=1);

namespace Waffy\Payment\Model;

use Psr\Log\LoggerInterface;
use Waffy\Ecommerce\Contract\ProgressReporter;
use Waffy\Ecommerce\Progress\CheckoutStep;
use Waffy\Ecommerce\Progress\StepStatus;

/**
 * Logs every Waffy call the module makes — one line per call with its duration,
 * so you can show afterwards which calls ran and which the token cache skipped.
 *
 * The checkout modal no longer polls this: it runs each step as its own request
 * and knows its progress first-hand.
 *
 * Nothing here may throw: logging must never be able to fail a payment.
 */
class CheckoutProgress implements ProgressReporter
{
    private string $context = 'checkout';

    private float $startedAt;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->startedAt = microtime(true);
    }

    /** Label the run in the log. Returns $this so it can be passed straight to the SDK. */
    public function start(string $unused, string $context): self
    {
        $this->context   = $context;
        $this->startedAt = microtime(true);

        return $this;
    }

    /** SDK callback — one call per step transition. `cached` means the call did not happen. */
    public function report(CheckoutStep $step, StepStatus $status, float $elapsedMs): void
    {
        if ($status === StepStatus::Running) {
            return; // the settled line carries the duration; both would be noise
        }

        try {
            $this->logger->info(sprintf(
                'Waffy: %s step=%s status=%s took=%.1fms elapsed=%.1fms',
                $this->context,
                $step->value,
                $status->value,
                $elapsedMs,
                (microtime(true) - $this->startedAt) * 1000,
            ));
        } catch (\Throwable) {
            return; // never worth failing a payment over
        }
    }
}
