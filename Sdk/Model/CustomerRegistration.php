<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

/**
 * Result of registering (or re-linking) a buyer on a Waffy ID organization.
 *
 * waffyUserId and userIdentification are Waffy's two ids for the buyer.
 * consent is null only on a Waffy ID build that predates the field.
 */
class CustomerRegistration
{
    public function __construct(
        public readonly string $waffyUserId,
        public readonly ?string $userIdentification,
        public readonly ?CustomerConsent $consent,
    ) {
    }

    /**
     * True when the organization must ask the buyer for consent (OTP) before
     * creating the contract.
     *
     * A response with no `consent` object answers false: there is nothing to
     * branch on, and checkout does not depend on it — Waffy's payment page
     * re-checks consent live and falls back to a login of its own.
     */
    public function consentRequired(): bool
    {
        return $this->consent?->required ?? false;
    }
}
