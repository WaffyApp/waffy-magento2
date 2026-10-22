<?php

declare(strict_types=1);

namespace Waffy\Payment\Controller\Checkout;

use Magento\Sales\Model\Order;

/** POST /waffy/checkout/verify — checks the buyer's OTP. */
class Verify extends AbstractStep
{
    protected function run(Order $order): array
    {
        return $this->steps->verify($order, is_string($this->body()['otp'] ?? null) ? $this->body()['otp'] : '');
    }
}
