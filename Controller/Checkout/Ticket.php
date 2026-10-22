<?php

declare(strict_types=1);

namespace Waffy\Payment\Controller\Checkout;

use Magento\Sales\Model\Order;

/** POST /waffy/checkout/ticket — the buyer's payment ticket (after prepare and consent). */
class Ticket extends AbstractStep
{
    protected function run(Order $order): array
    {
        return $this->steps->ticket($order);
    }
}
