<?php

declare(strict_types=1);

namespace Waffy\Payment\Controller\Checkout;

use Magento\Sales\Model\Order;

/** POST /waffy/checkout/prepare — contract → milestone → parties (runs in parallel with begin). */
class Prepare extends AbstractStep
{
    protected function run(Order $order): array
    {
        return $this->steps->prepare($order);
    }
}
