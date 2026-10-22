<?php

declare(strict_types=1);

namespace Waffy\Payment\Controller\Checkout;

use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Model\Order;

/**
 * POST /waffy/checkout/cancel — the buyer gave up at the OTP step: cancel the
 * order, remove its Waffy contract (best-effort), and put the items back in
 * their cart so they can choose another payment method.
 *
 * Unlike the other steps this one keeps the session open: restoring the quote
 * writes to it.
 */
class Cancel extends AbstractStep
{
    public function execute(): ResultInterface
    {
        $json  = $this->jsonFactory->create();
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order || !$order->getId()) {
            return $json->setHttpResponseCode(404)->setData(['error' => 'not_found']);
        }

        $this->run($order);
        if ($order->getState() === Order::STATE_CANCELED) {
            $this->checkoutSession->restoreQuote();
        }

        return $json->setData(['ok' => true]);
    }

    protected function run(Order $order): array
    {
        $this->steps->cancel($order);

        return ['ok' => true];
    }
}
