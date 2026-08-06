<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Dto;

use Waffy\Ecommerce\Exception\ValidationException;
use Waffy\Ecommerce\Support\PhoneNumber;

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
        if (!PhoneNumber::isValidE164($phoneNumber)) {
            throw new ValidationException(
                sprintf('CustomerInfo: phoneNumber must be E.164 (e.g. +966555555555), got "%s".', $phoneNumber),
            );
        }
    }

    /**
     * The buyer's stable Waffy identity: the merchant-supplied clientUserId when
     * there is one, otherwise the phone digits.
     *
     * This is the key used for sign-up, login AND the customer-token cache, so it
     * has to be derived identically everywhere — a checkout that resolved the key
     * one way and a login prefetch that resolved it another would never share a
     * cached token. Hence: one method, not a rule each caller re-implements.
     */
    public function resolveClientUserId(): string
    {
        return $this->clientUserId ?? PhoneNumber::toClientUserId($this->phoneNumber);
    }
}
