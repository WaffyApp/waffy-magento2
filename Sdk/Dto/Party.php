<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Dto;

use Waffy\Ecommerce\Exception\ValidationException;
use Waffy\Ecommerce\Support\PhoneNumber;

/**
 * A participant in a milestone contract, identified by exactly one of:
 *
 * phoneNumber — an E.164 mobile; how the buyer (CUSTOMER) is identified.
 * userId      — a Waffy account id; how the merchant (PROVIDER) is identified —
 *               the organization's own account, `org_account_id`.
 *
 * role     — CUSTOMER | PROVIDER
 * amount   — the SAR amount this party is responsible for / receives.
 * isSender — true when this party initiates the payment (the PROVIDER).
 */
readonly class Party
{
    private const VALID_ROLES = ['CUSTOMER', 'PROVIDER'];

    public function __construct(
        public ?string $phoneNumber,
        public string $role,
        public float $amount,
        public bool $isSender = false,
        public ?string $userId = null,
    ) {
        if (!in_array($role, self::VALID_ROLES, true)) {
            throw new ValidationException(
                sprintf('Party role must be one of %s, got "%s".', implode(', ', self::VALID_ROLES), $role),
            );
        }
        if (($phoneNumber === null) === ($userId === null)) {
            throw new ValidationException('Party needs exactly one of phoneNumber or userId.');
        }
        if ($phoneNumber !== null && !PhoneNumber::isValidE164($phoneNumber)) {
            throw new ValidationException(
                sprintf('Party phoneNumber must be E.164, got "%s".', $phoneNumber),
            );
        }
        if ($userId !== null && !self::isAccountId($userId)) {
            throw new ValidationException(
                sprintf('Party userId must be a Waffy account id (digits), got "%s".', $userId),
            );
        }
    }

    /**
     * The standard escrow party set for a single-milestone purchase:
     *   - CUSTOMER — the buyer, by phone, responsible for the full amount;
     *   - PROVIDER — the merchant's Waffy account, by userId, who initiates
     *                (sends) the payment and receives the full amount.
     *
     * This is the Waffy escrow model, identical across every storefront, so it
     * lives here rather than being re-derived in each platform adapter.
     *
     * @param string $providerUserId The organization's Waffy account id (`org_account_id`).
     *
     * @return Party[]
     */
    public static function escrowSet(string $buyerPhone, string $providerUserId, float $amount): array
    {
        return [
            new self($buyerPhone, 'CUSTOMER', $amount),
            new self(null, 'PROVIDER', $amount, isSender: true, userId: $providerUserId),
        ];
    }

    /** A Waffy account id: a positive integer, as digits. */
    public static function isAccountId(string $value): bool
    {
        return preg_match('/^[1-9][0-9]*$/', $value) === 1;
    }

    /**
     * Wire shape for `PATCH /contracts/{id}/parties`. `userId` is sent as a
     * string, as the collection's example does (`"{{org_account_id}}"`).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->phoneNumber !== null
            ? ['phoneNumber' => $this->phoneNumber]
            : ['userId' => $this->userId];
        $data['role'] = $this->role;
        $data['amount'] = $this->amount;
        if ($this->isSender) {
            $data['isSender'] = true;
        }

        return $data;
    }
}
