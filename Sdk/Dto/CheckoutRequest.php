<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Dto;

use Waffy\Ecommerce\Exception\ValidationException;

/**
 * Full input to EcomCheckoutOrchestrator::initiateCheckout().
 *
 * clientId          — OAuth2 client ID issued to the merchant.
 * clientSecret       — OAuth2 client secret.
 * clientAdminEmail   — Waffy admin account email for the merchant.
 * clientAdminPassword — Waffy admin account password for the merchant.
 * customer           — buyer to register / authenticate in Waffy.
 * product            — the item or service being sold.
 * milestone          — payment amount and deadline for this order.
 * parties            — all participants: CUSTOMER, PROVIDER (merchant), optional BROKER.
 * redirectUrl        — where Waffy redirects the buyer after they complete payment.
 * paymentType        — always PURCHASE for standard checkout (PURCHASE | SUBSCRIPTION).
 */
readonly class CheckoutRequest
{
    /**
     * @param Party[] $parties At minimum: one CUSTOMER and one PROVIDER.
     */
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public string $clientAdminEmail,
        public string $clientAdminPassword,
        public CustomerInfo $customer,
        public ProductInfo $product,
        public MilestoneInfo $milestone,
        public array $parties,
        public string $redirectUrl,
        public string $paymentType = 'PURCHASE',
    ) {
        if (trim($clientId) === '') {
            throw new ValidationException('CheckoutRequest: clientId cannot be empty.');
        }
        if (trim($clientSecret) === '') {
            throw new ValidationException('CheckoutRequest: clientSecret cannot be empty.');
        }
        if (trim($clientAdminEmail) === '') {
            throw new ValidationException('CheckoutRequest: clientAdminEmail cannot be empty.');
        }
        if (trim($clientAdminPassword) === '') {
            throw new ValidationException('CheckoutRequest: clientAdminPassword cannot be empty.');
        }
        if (trim($redirectUrl) === '') {
            throw new ValidationException('CheckoutRequest: redirectUrl cannot be empty.');
        }
        if (count($parties) < 2) {
            throw new ValidationException('CheckoutRequest: at least two parties (CUSTOMER + PROVIDER) are required.');
        }
    }
}
