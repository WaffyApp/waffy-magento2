<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Client;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Throwable;

/**
 * Retries a callable using exponential backoff with full jitter.
 *
 * Retries on:
 *   - 5xx server responses (GuzzleHttp\Exception\ServerException)
 *   - Network failures (GuzzleHttp\Exception\ConnectException)
 *
 * Does NOT retry on:
 *   - 4xx client errors (caller's fault — bad token, bad payload, etc.)
 *   - Application-level exceptions (validation, auth, etc.)
 *
 * Backoff schedule (default maxAttempts=3, baseDelayMs=100, maxDelayMs=2000):
 *   Attempt 1: 0ms
 *   Attempt 2: rand(0, min(100 * 2^0, 2000)) = 0–100ms
 *   Attempt 3: rand(0, min(100 * 2^1, 2000)) = 0–200ms
 */
class RetryEngine
{
    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 100,
        private readonly int $maxDelayMs = 2000,
        private readonly ?Closure $sleeper = null,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be >= 1.');
        }
        if ($baseDelayMs < 0 || $maxDelayMs < 0) {
            throw new \InvalidArgumentException('Delays must be non-negative.');
        }
    }

    /**
     * @template T
     * @param Closure():T $operation
     * @return T
     */
    public function execute(Closure $operation): mixed
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxAttempts) {
            try {
                return $operation();
            } catch (ServerException | ConnectException $e) {
                $lastException = $e;
                $attempt++;
                if ($attempt >= $this->maxAttempts) {
                    break;
                }
                $this->sleepFor($this->computeDelayMs($attempt));
            } catch (Throwable $e) {
                // Non-retryable — bubble up immediately.
                throw $e;
            }
        }

        // All attempts exhausted on a retryable error.
        throw $lastException;
    }

    /**
     * Compute the delay for the NEXT attempt after a failure on the given
     * attempt number (1-indexed).
     */
    private function computeDelayMs(int $attemptNumber): int
    {
        $cap = min($this->baseDelayMs * (2 ** ($attemptNumber - 1)), $this->maxDelayMs);

        return random_int(0, $cap);
    }

    private function sleepFor(int $delayMs): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($delayMs);

            return;
        }
        usleep($delayMs * 1000);
    }
}
