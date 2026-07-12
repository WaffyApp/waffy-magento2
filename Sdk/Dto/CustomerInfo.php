<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Dto;

use Waffy\Ecommerce\Exception\ValidationException;

/**
 * Buyer account to register (or look up) in Waffy before creating a contract.
 *
 * phoneNumber   — E.164 format, e.g. +966555555555. Required.
 * clientUserId  — optional merchant-side user ID; falls back to phone digits in the orchestrator.
 * password      — unused by the API; omit or pass null.
 */
readonly class CustomerInfo
{
    public function __construct(
        public string $phoneNumber,
        public string $firstName,
        public string $lastName,
        public ?string $clientUserId = null,
        public ?string $password = null,
    ) {
        if (!preg_match('/^\+\d{8,15}$/', $phoneNumber)) {
            throw new ValidationException(
                sprintf('CustomerInfo: phoneNumber must be E.164 (e.g. +966555555555), got "%s".', $phoneNumber),
            );
        }
    }
}
