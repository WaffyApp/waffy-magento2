<?php

declare(strict_types=1);

namespace Waffy\Payment\Model;

use Magento\Payment\Model\Method\AbstractMethod;

/**
 * Waffy escrow payment method.
 *
 * This is an offline/redirect payment method. When the buyer places an order:
 *   1. Magento creates the order with status pending_payment.
 *   2. getOrderPlaceRedirectUrl() returns the URL to our Start controller.
 *   3. The Start controller calls the Waffy SDK and redirects the buyer
 *      to the Waffy-hosted payment page.
 *   4. After payment, Waffy calls our webhook endpoint.
 *   5. The buyer is sent back to our Return controller.
 */
class Payment extends AbstractMethod
{
    public const CODE = 'waffy_payment';

    protected $_code = self::CODE;

    protected $_isOffline = false;
    protected $_isGateway = false;
    protected $_canOrder = true;
    protected $_canAuthorize = false;
    protected $_canCapture = false;
    protected $_canRefund = false;
    protected $_canVoid = false;
    protected $_canUseInternal = false;
    protected $_canUseCheckout = true;

    /**
     * After order placement Magento checks this URL and redirects the buyer.
     * The Start controller then calls the SDK and forwards to Waffy.
     */
    public function getOrderPlaceRedirectUrl(): string
    {
        return 'waffy/checkout/start';
    }
}
