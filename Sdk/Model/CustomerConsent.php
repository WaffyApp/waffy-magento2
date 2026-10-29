<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

/**
 * The `consent` object Waffy ID returns with every `waffy-partner/customers`
 * call, about the link between this buyer and the calling client only:
 *
 *   { "status": "PENDING", "type": null, "required": true,
 *     "autoAccept": false, "linkSent": false }
 *
 * `required` is the field to branch on: true means the organization must get
 * the buyer's consent (OTP or signed link) before it acts for them. A buyer
 * who consented once stays consented for this client, so a repeat call for
 * the same buyer answers VERIFIED / `required: false` and nothing is asked
 * twice.
 *
 * status     — PENDING | VERIFIED | REJECTED, or null when Waffy sent something else.
 * type       — how a VERIFIED consent was established: CUSTOMER_APPROVED (signed
 *              link), AUTO_CONTRACTUAL (auto-accepted client) or LIVE_OTP_VERIFIED.
 *              Null while PENDING or REJECTED.
 * autoAccept — this client's `auto-accept-consent` setting, read live. Do not cache it long.
 * linkSent   — the signed-link SMS was accepted for sending on this call
 *              (consentMethod 'sms' only).
 */
class CustomerConsent
{
    public const STATUSES = ['PENDING', 'VERIFIED', 'REJECTED'];
    public const TYPES    = ['CUSTOMER_APPROVED', 'AUTO_CONTRACTUAL', 'LIVE_OTP_VERIFIED'];

    public function __construct(
        public readonly ?string $status,
        public readonly ?string $type,
        public readonly bool $required,
        public readonly bool $autoAccept,
        public readonly bool $linkSent,
    ) {
    }

    /**
     * Parse the response's `consent` object, or null when there is none (a
     * Waffy ID build from before the field existed).
     *
     * An unreadable `required` falls back to "required unless VERIFIED", the
     * rule the backend itself states.
     */
    public static function fromResponse(mixed $value): ?self
    {
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            return null;
        }

        $status   = in_array($value['status'] ?? null, self::STATUSES, true) ? $value['status'] : null;
        $type     = in_array($value['type'] ?? null, self::TYPES, true) ? $value['type'] : null;
        $required = $value['required'] ?? null;

        return new self(
            status: $status,
            type: $type,
            required: is_bool($required) ? $required : $status !== 'VERIFIED',
            autoAccept: ($value['autoAccept'] ?? null) === true,
            linkSent: ($value['linkSent'] ?? null)     === true,
        );
    }

    /** True when the buyer already declined this organization. */
    public function isRejected(): bool
    {
        return $this->status === 'REJECTED';
    }
}
