<?php

declare(strict_types=1);

namespace Waffy\Payment\Controller\Checkout;

use Magento\Sales\Model\Order;

/** POST /waffy/checkout/startPayment — the payment page URL (after prepare). */
class StartPayment extends AbstractStep
{
    protected function run(Order $order): array
    {
        return $this->steps->startPayment($order);
    }
}
