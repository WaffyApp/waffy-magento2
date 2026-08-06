<?php

declare(strict_types=1);

namespace Waffy\Payment\Model;

use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;
use Waffy\Ecommerce\Contract\ProgressReporter;
use Waffy\Ecommerce\Progress\CheckoutStep;
use Waffy\Ecommerce\Progress\StepStatus;

/**
 * Records the checkout call sequence: to the log, and to a cache entry the open
 * modal polls.
 *
 * One SDK ProgressReporter feeding two consumers, because they answer the same
 * question at different timescales:
 *
 *   - the log is the durable record — every call with a timestamp and duration,
 *     so you can show afterwards that the tokens came from the warm-up or the
 *     login prefetch and that checkout made only the four contract calls;
 *   - the cache entry is the live view, read by Controller\Checkout\Progress
 *     while the Start controller is still running.
 *
 * Why the cache and not the session: Magento holds the PHP session lock for a
 * whole request, so a poll that touched the session would block until checkout
 * finished — arriving all at once at the end, which is the opposite of live. The
 * cache is reachable without a session, keyed by a cookie the browser sets.
 *
 * Nothing here may throw: a progress display must never be able to fail a
 * payment.
 */
class CheckoutProgress implements ProgressReporter
{
    /** Cookie the browser sets before placing the order. */
    public const COOKIE = 'waffy_progress_key';

    /** Cache id prefix; one entry per in-flight checkout. */
    private const CACHE_PREFIX = 'waffy_progress_';

    /** Cache tag, so a cache:flush clears these along with everything else. */
    private const CACHE_TAG = 'WAFFY_PROGRESS';

    /** Long enough to outlive the slowest checkout, short enough to self-clean. */
    private const TTL = 300;

    /** @var array<string, array{status: string, ms: float}> */
    private array $steps = [];

    private string $key = '';

    private string $context = 'checkout';

    private float $startedAt;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
        $this->startedAt = microtime(true);
    }

    /**
     * Set the progress key and the label used in the log.
     *
     * A DI-constructed object cannot take these through the constructor, so this
     * is the initialiser. An empty key means nothing is watching: the run is
     * still logged, just not published.
     */
    public function start(string $key, string $context): self
    {
        $this->key       = self::sanitizeKey($key);
        $this->context   = $context;
        $this->steps     = [];
        $this->startedAt = microtime(true);

        return $this;
    }

    /**
     * Untrusted input: it names a cache entry, so only a conservative alphabet is
     * accepted rather than anything that survives escaping.
     */
    public static function sanitizeKey(string $raw): string
    {
        return preg_match('/^[A-Za-z0-9]{8,64}$/', $raw) === 1 ? $raw : '';
    }

    /** SDK callback — one call per step transition. */
    public function report(CheckoutStep $step, StepStatus $status, float $elapsedMs): void
    {
        try {
            $this->log($step, $status, $elapsedMs);

            $this->steps[$step->value] = [
                'status' => $status->value,
                'ms'     => $elapsedMs,
            ];

            $this->publish();
        } catch (\Throwable $e) {
            return; // never worth failing a payment over
        }
    }

    /**
     * Mark the whole run finished so the modal can stop polling, rather than
     * waiting for its next tick to notice nothing more is coming.
     */
    public function finish(bool $success): void
    {
        try {
            $this->publish($success ? 'complete' : 'failed');
        } catch (\Throwable $e) {
            return;
        }
    }

    /** Read the live state for $key, or null when there is nothing in flight. */
    public function read(string $key): ?array
    {
        $key = self::sanitizeKey($key);
        if ($key === '') {
            return null;
        }

        $raw = $this->cache->load(self::CACHE_PREFIX . $key);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $state = json_decode($raw, true);

        return is_array($state) ? $state : null;
    }

    // ── Consumers ────────────────────────────────────────────────────────────

    /**
     * One line per call. `cached` lines are the ones to look for when verifying
     * the token cache: they mean the call did not happen at all.
     */
    private function log(CheckoutStep $step, StepStatus $status, float $elapsedMs): void
    {
        if ($status === StepStatus::Running) {
            return; // the settled line carries the duration; both would be noise
        }

        $this->logger->info(sprintf(
            'Waffy: %s step=%s status=%s took=%.1fms elapsed=%.1fms',
            $this->context,
            $step->value,
            $status->value,
            $elapsedMs,
            (microtime(true) - $this->startedAt) * 1000,
        ));
    }

    private function publish(string $state = 'running'): void
    {
        if ($this->key === '') {
            return; // no browser is watching
        }

        $this->cache->save(
            (string) json_encode([
                'state'      => $state,
                'steps'      => $this->steps,
                'updated_at' => microtime(true),
            ]),
            self::CACHE_PREFIX . $this->key,
            [self::CACHE_TAG],
            self::TTL,
        );
    }
}
