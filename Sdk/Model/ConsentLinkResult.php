<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

/**
 * Answer of `POST waffy-partner/consent/send`.
 *
 *   { "sent": true }
 *   { "sent": false, "reason": "ALREADY_VERIFIED", ... }
 *   { "sent": false, "reason": "RECENTLY_SENT", "retryAfterSeconds": 42 }
 *
 * `sent: true` means accepted for sending, not delivered. A dispatch failure
 * is an HTTP 502, not `sent: false`, and surfaces as an exception.
 *
 * reason            — ALREADY_VERIFIED | RECENTLY_SENT, neither a failure; a reason
 *                     Waffy adds later is kept as-is rather than dropped.
 * retryAfterSeconds — set with RECENTLY_SENT: the per-buyer cooldown is 60 seconds.
 */
readonly class ConsentLinkResult
{
    public function __construct(
        public bool $sent,
        public ?string $reason,
        public int|float|null $retryAfterSeconds,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromResponse(array $body): self
    {
        $reason = $body['reason'] ?? null;
        $retryAfter = $body['retryAfterSeconds'] ?? null;

        return new self(
            sent: ($body['sent'] ?? null) === true,
            reason: is_string($reason) && $reason !== '' ? $reason : null,
            retryAfterSeconds: is_int($retryAfter) || is_float($retryAfter) && is_finite($retryAfter) ? $retryAfter : null,
        );
    }
}
