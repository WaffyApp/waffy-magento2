<?php

declare(strict_types=1);

namespace Waffy\Payment\Controller\Checkout;

use Magento\Sales\Model\Order;

/** POST /waffy/checkout/begin — Link Customer; sends an OTP only when consent is required. */
class Begin extends AbstractStep
{
    protected function run(Order $order): array
    {
        return $this->steps->begin($order, ($this->body()['resend'] ?? false) === true);
    }
}
