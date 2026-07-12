<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Dto;

use Waffy\Ecommerce\Exception\ValidationException;

/**
 * A participant in a milestone contract.
 *
 * role        — CUSTOMER | PROVIDER | BROKER
 * amount      — the SAR amount this party is responsible for / receives.
 * isSender    — true when this party initiates the payment (usually the PROVIDER).
 * arbitrator  — true when this BROKER can adjudicate disputes.
 */
readonly class Party
{
    private const VALID_ROLES = ['CUSTOMER', 'PROVIDER', 'BROKER'];

    public function __construct(
        public string $phoneNumber,
        public string $role,
        public float $amount,
        public bool $isSender = false,
        public bool $arbitrator = false,
    ) {
        if (!in_array($role, self::VALID_ROLES, true)) {
            throw new ValidationException(
                sprintf('Party role must be one of %s, got "%s".', implode(', ', self::VALID_ROLES), $role),
            );
        }
        if (!preg_match('/^\+\d{8,15}$/', $phoneNumber)) {
            throw new ValidationException(
                sprintf('Party phoneNumber must be E.164, got "%s".', $phoneNumber),
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'phoneNumber' => $this->phoneNumber,
            'role'        => $this->role,
            'amount'      => $this->amount,
        ];
        if ($this->isSender) {
            $data['isSender'] = true;
        }
        if ($this->arbitrator) {
            $data['arbitrator'] = true;
        }

        return $data;
    }
}
