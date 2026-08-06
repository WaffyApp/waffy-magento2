<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Progress;

/**
 * What happened to a {@see CheckoutStep}.
 *
 * `Cached` is the interesting one: it means the call was *not* made because a
 * valid token was already in the TokenStore. A checkout that reports four
 * cached auth steps and four performed contract steps is the token cache doing
 * exactly what it was built to do — which is why it is a distinct status rather
 * than just another `Done`.
 */
enum StepStatus: string
{
    /** The call is in flight. */
    case Running = 'running';

    /** The call completed successfully. */
    case Done = 'done';

    /** Skipped — a valid token was already cached. */
    case Cached = 'cached';

    /** The call failed; checkout is aborting (or retrying without the cache). */
    case Failed = 'failed';

    /** True once a step needs no further work — for progress-bar arithmetic. */
    public function isSettled(): bool
    {
        return $this !== self::Running;
    }
}
