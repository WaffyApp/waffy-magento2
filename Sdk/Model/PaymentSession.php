<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

/**
 * Result returned from CheckoutOrchestrator::createPayment().
 *
 * The platform adapter stores `contractId` against the order (for webhook
 * correlation) and redirects the buyer to `paymentUrl` to complete payment.
 *
 * `paymentUrl` is the Waffy-hosted page (e.g.
 * https://app-dev.waffyapp.com/external/UXFmUCaG?client_id=...). On success
 * or failure Waffy returns the buyer to the platform's return URL with a
 * status query param (see WaffyPaymentWebView pattern in the RN SDK).
 */
final readonly class PaymentSession
{
    public function __construct(
        public string $contractId,
        public string $paymentUrl,
        public Money $amount,
        public PaymentStatus $status,
        public ?string $shortId = null,
    ) {
    }
}
