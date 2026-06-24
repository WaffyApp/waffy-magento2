<?php

declare(strict_types=1);

namespace Waffy\Payment\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;

/**
 * GET /waffy/checkout/return?order_id=000000001
 *
 * Landing page after the buyer returns from the Waffy payment page.
 * Waffy also sends a webhook for definitive order status — this controller
 * just provides the buyer redirect back to the Magento success/failure page.
 *
 * The actual order status update happens in the Webhook controller.
 */
class ReturnAction implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly OrderFactory $orderFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(): \Magento\Framework\Controller\ResultInterface
    {
        $incrementId = (string) $this->request->getParam('order_id', '');

        if ($incrementId === '') {
            return $this->redirectFactory->create()->setPath('checkout/cart');
        }

        $order = $this->orderFactory->create()->loadByIncrementId($incrementId);

        if (!$order->getId()) {
            $this->logger->warning('Waffy return: unknown order_id=' . $incrementId);
            return $this->redirectFactory->create()->setPath('checkout/cart');
        }

        // Restore checkout session so the success page can render order details
        $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
        $this->checkoutSession->setLastQuoteId($order->getQuoteId());
        $this->checkoutSession->setLastOrderId($order->getId());
        $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
        $this->checkoutSession->setLastOrderStatus($order->getStatus());

        $this->messageManager->addSuccessMessage(
            __('Your order has been placed. We are waiting for payment confirmation from Waffy.'),
        );

        return $this->redirectFactory->create()->setPath('checkout/onepage/success');
    }
}
