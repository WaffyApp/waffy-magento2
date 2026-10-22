<?php

declare(strict_types=1);

namespace Waffy\Payment\Model;

use Magento\Payment\Model\Method\AbstractMethod;

/**
 * Waffy escrow payment method.
 *
 * A redirect method. When the buyer places an order:
 *   1. Magento creates the order (pending_payment).
 *   2. The checkout modal runs the Waffy steps (Controller\Checkout\*, see
 *      Model\CheckoutSteps) — asking the buyer for an OTP when Waffy says their
 *      consent is required — and sends them to the Waffy payment page.
 *   3. After payment, Waffy calls our webhook endpoint.
 *   4. The buyer is sent back to our Return controller.
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
}
