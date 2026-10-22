<?php

declare(strict_types=1);

namespace Waffy\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Waffy\Payment\Model\CheckoutSteps;
use Waffy\Payment\Model\Payment;

/**
 * An unpaid Waffy order was cancelled (by the merchant, or by Magento's own
 * pending-payment cleanup): remove its contract on Waffy too. Best-effort —
 * a failure leaves the order flagged for Cron\DiscardAbandonedContracts.
 */
class DiscardContractOnOrderCancel implements ObserverInterface
{
    public function __construct(
        private readonly CheckoutSteps $steps,
    ) {}

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        if ($order instanceof Order && $order->getPayment()?->getMethod() === Payment::CODE) {
            $this->steps->discard($order);
        }
    }
}
