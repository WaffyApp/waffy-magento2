<?php

declare(strict_types=1);

namespace Waffy\Payment\Model;

use Magento\Sales\Model\Order;
use Waffy\Ecommerce\Dto\CheckoutRequest;
use Waffy\Ecommerce\Dto\CustomerInfo;
use Waffy\Ecommerce\Dto\MilestoneInfo;
use Waffy\Ecommerce\Dto\Party;
use Waffy\Ecommerce\Dto\ProductInfo;
use Waffy\Ecommerce\Exception\ValidationException;
use Waffy\Ecommerce\Support\PhoneNumber;

/**
 * Magento order → Waffy CheckoutRequest. Only the mapping lives here; the
 * escrow parties, phone rules, deadline and contract defaults are the SDK's.
 */
class CheckoutRequestBuilder
{
    public function __construct(
        private readonly Config $config,
    ) {}

    /** @throws ValidationException When the order has no usable mobile number. */
    public function build(Order $order): CheckoutRequest
    {
        $storeId  = (int) $order->getStoreId();
        $billing  = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();

        // Use the number the buyer entered for THIS order first. In Magento the
        // freshly-typed number lands on the shipping address; the billing address
        // can silently keep the customer's saved default (see order #61). Fall
        // back to billing, then to empty — we never fabricate a placeholder.
        $phone = PhoneNumber::toE164((string) ($shipping?->getTelephone() ?: $billing?->getTelephone() ?: ''));
        if (!PhoneNumber::isValidE164($phone)) {
            throw new ValidationException(
                'A valid mobile number is required to pay with Waffy. Please add your phone number and try again.',
            );
        }

        $email = (string) ($order->getCustomerEmail() ?: $billing?->getEmail() ?: '');
        $customer = new CustomerInfo(
            phoneNumber: $phone,
            firstName:   (string) ($billing?->getFirstname() ?? $order->getCustomerFirstname() ?? 'Customer'),
            lastName:    (string) ($billing?->getLastname() ?? $order->getCustomerLastname() ?? 'Guest'),
            email:       $email !== '' ? $email : null,
        );

        $itemNames = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $itemNames[] = $item->getName() . ' x' . (int) $item->getQtyOrdered();
        }

        $amount = (float) $order->getGrandTotal();

        return new CheckoutRequest(
            clientId:            $this->config->getClientId($storeId),
            clientSecret:        $this->config->getClientSecret($storeId),
            clientAdminEmail:    $this->config->getClientAdminEmail($storeId),
            clientAdminPassword: $this->config->getClientAdminPassword($storeId),
            customer:            $customer,
            // Category, return policy and fee payee: the SDK's ProductInfo defaults.
            product:             new ProductInfo(
                title:       $this->config->getStoreName($storeId) . ' - Order #' . $order->getIncrementId(),
                description: implode(', ', $itemNames),
            ),
            // Deadline: MilestoneInfo::DEFAULT_DAYS, the same on every platform.
            milestone:           MilestoneInfo::inDays(amount: $amount),
            // The PROVIDER is the organization's own Waffy account (org_account_id).
            parties:             Party::escrowSet($phone, $this->config->getOrgAccountId($storeId), $amount),
            redirectUrl:         $order->getStore()->getBaseUrl() . 'waffy/checkout/return?order_id=' . $order->getIncrementId(),
            paymentType:         $this->config->getPaymentType($storeId),
        );
    }
}
