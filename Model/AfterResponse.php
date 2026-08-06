<?php

declare(strict_types=1);

namespace Waffy\Payment\Model;

use Psr\Log\LoggerInterface;

/**
 * Runs a task after the response has been handed to the browser.
 *
 * The token prefetch costs a couple of seconds of Waffy round trips, and it is
 * triggered by a shopper opening a page or logging in — neither of which should
 * wait for it. PHP has no background worker, but under PHP-FPM
 * fastcgi_finish_request() closes the connection and lets the script keep
 * running, which is enough: the shopper sees their page immediately and the
 * warm-up happens on a worker they are no longer waiting for.
 *
 * Ordering note: Magento registers its own shutdown handler for
 * SessionManager::writeClose() when the session starts, i.e. before ours, so the
 * session is already written and unlocked by the time our task runs. That is why
 * a deferred task must never write to the session — callers record their
 * bookkeeping in the request itself, before deferring.
 *
 * Without FPM (CLI, mod_php) the task still runs, just at the normal end of the
 * script. Nothing here may throw: a failure in a deferred task must not become a
 * 500 on a page that has already been delivered.
 */
class AfterResponse
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function run(callable $task): void
    {
        register_shutdown_function(function () use ($task): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            try {
                $task();
            } catch (\Throwable $e) {
                $this->logger->warning('Waffy: deferred task failed: ' . $e->getMessage());
            }
        });
    }
}
