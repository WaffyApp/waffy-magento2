<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

/**
 * Answer of `POST waffy-partner/otp/send`: `{ "sent": true }`.
 *
 * sent   — Waffy accepted the OTP for sending. When false, show the buyer that
 *          no code is on its way, with `reason` when Waffy gave one.
 * reason — Waffy's explanation for `sent: false`, kept as-is; null when absent.
 *
 * Rate limits (429), a bad phone (400) and a failed dispatch (502) are not
 * `sent: false` — they surface as an AuthException carrying getUserMessage().
 */
readonly class OtpSendResult
{
    public function __construct(
        public bool $sent,
        public ?string $reason,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromResponse(array $body): self
    {
        $reason = $body['reason'] ?? $body['message'] ?? null;

        return new self(
            sent: ($body['sent'] ?? null) === true,
            reason: is_string($reason) && $reason !== '' ? $reason : null,
        );
    }
}
