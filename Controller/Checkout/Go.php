<?php

declare(strict_types=1);

namespace Waffy\Payment\Controller\Checkout;

use Magento\Sales\Model\Order;

/** POST /waffy/checkout/go — the final Waffy URL, once start-payment and the ticket are done. */
class Go extends AbstractStep
{
    protected function run(Order $order): array
    {
        return $this->steps->go($order);
    }
}
