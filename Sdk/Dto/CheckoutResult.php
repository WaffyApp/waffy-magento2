<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Dto;

/**
 * Returned by EcomCheckoutOrchestrator::initiateCheckout().
 *
 * paymentUrl    — redirect the buyer to this URL to complete payment.
 * customerToken — JWT obtained from the Customer Login step. Store it
 *                 server-side (encrypted); it is required for any future
 *                 calls made on behalf of this buyer (e.g. contract actions).
 * contractId    — Waffy parent contract ID, for your order records.
 * milestoneId   — Waffy milestone ID, for your order records.
 */
readonly class CheckoutResult
{
    public function __construct(
        public string $paymentUrl,
        public string $customerToken,
        public string $contractId,
        public string $milestoneId,
    ) {}
}
