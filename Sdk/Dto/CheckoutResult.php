<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Dto;

use Waffy\Ecommerce\Model\CustomerConsent;

/**
 * Returned by EcomCheckoutOrchestrator::initiateCheckout().
 *
 * paymentUrl      — the Waffy payment page for this contract, without the buyer
 *                   credential. Prefer paymentUrlWithBuyer(), which appends it.
 * customerToken   — JWT obtained from the Customer Login step. Store it
 *                   server-side (encrypted); it is required for any future
 *                   calls made on behalf of this buyer (e.g. contract actions).
 *                   Empty string on AuthMode::Keycloak, which issues a
 *                   single-use payment ticket instead of a reusable buyer JWT.
 * contractId      — Waffy parent contract ID, for your order records.
 * milestoneId     — Waffy milestone ID, for your order records. This is the key
 *                   inbound webhooks arrive under (as their `contractId` field).
 * buyerCredential — what paymentUrlWithBuyer() appends. Omit it on the legacy
 *                   path and it is derived from customerToken.
 * consent         — AuthMode::Keycloak only: the buyer's consent when they were
 *                   registered for this checkout. Null on AuthMode::Legacy.
 */
readonly class CheckoutResult
{
    public BuyerCredential $buyerCredential;

    public function __construct(
        public string $paymentUrl,
        public string $customerToken,
        public string $contractId,
        public string $milestoneId,
        ?BuyerCredential $buyerCredential = null,
        public ?CustomerConsent $consent = null,
    ) {
        $this->buyerCredential = $buyerCredential ?? new BuyerCredential('userTokenUrl', $customerToken);
    }

    /**
     * The result of the step-by-step Waffy ID checkout (prepareContract(),
     * startPayment(), paymentTicket()), for a platform that drove the calls
     * itself rather than through initiateCheckout().
     */
    public static function withTicket(
        PreparedContract $contract,
        string $paymentUrl,
        string $ticket,
        ?CustomerConsent $consent = null,
    ): self {
        return new self(
            paymentUrl: $paymentUrl,
            customerToken: '',
            contractId: $contract->contractId,
            milestoneId: $contract->milestoneId,
            buyerCredential: new BuyerCredential('ticket', $ticket),
            consent: $consent,
        );
    }

    /**
     * The URL to actually redirect the buyer to: the payment page plus whichever
     * buyer credential this organization's auth mode issues.
     *
     * This is the one place that knows the parameter name, so a platform never
     * has to branch on AuthMode to send a shopper to Waffy.
     */
    public function paymentUrlWithBuyer(): string
    {
        if ($this->buyerCredential->value === '') {
            return $this->paymentUrl;
        }
        $separator = str_contains($this->paymentUrl, '?') ? '&' : '?';

        return $this->paymentUrl . $separator . $this->buyerCredential->param . '=' . rawurlencode($this->buyerCredential->value);
    }
}
