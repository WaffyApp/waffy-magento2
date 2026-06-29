<?php

declare(strict_types=1);

namespace Waffy\Payment\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Waffy\Ecommerce\Dto\CheckoutRequest;
use Waffy\Ecommerce\Dto\CustomerInfo;
use Waffy\Ecommerce\Dto\MilestoneInfo;
use Waffy\Ecommerce\Dto\Party;
use Waffy\Ecommerce\Dto\ProductInfo;
use Waffy\Ecommerce\Exception\ApiException;
use Waffy\Ecommerce\Exception\AuthException;
use Waffy\Payment\Model\Config;
use Waffy\Payment\Model\OrchestratorFactory;

/**
 * GET /waffy/checkout/start
 *
 * Called after the order is placed. Builds a CheckoutRequest from the last
 * order, calls the Waffy SDK, and redirects the buyer to the Waffy payment page.
 */
class Start implements HttpGetActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly Config $config,
        private readonly OrchestratorFactory $orchestratorFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(): \Magento\Framework\Controller\ResultInterface
    {
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order || !$order->getId()) {
            return $this->redirectFactory->create()->setPath('checkout/cart');
        }

        try {
            $request = $this->buildCheckoutRequest($order);

            $this->logger->info('Waffy checkout starting', [
                'order'        => $order->getIncrementId(),
                'clientUserId' => $request->customer->clientUserId,
                'phone'        => $request->customer->phoneNumber,
            ]);

            $result  = $this->orchestratorFactory->create((int) $order->getStoreId())->initiateCheckout($request);

            // Mark as pending_payment and store the milestone ID before redirecting.
            $order->setState(Order::STATE_PENDING_PAYMENT)
                  ->setStatus('pending_payment');
            $order->setExtOrderId($result->milestoneId);
            $this->orderRepository->save($order);

            $finalUrl = $result->paymentUrl . '&userTokenUrl=' . urlencode($result->customerToken);

            $this->checkoutSession->setWaffyPaymentUrl($finalUrl);

            $redirect = $this->redirectFactory->create();
            $redirect->setUrl($finalUrl);
            return $redirect;

        } catch (AuthException | ApiException $e) {
            $this->logger->error('Waffy checkout failed for order #' . $order->getIncrementId(), [
                'exception' => $e->getMessage(),
                'previous'  => $e->getPrevious()?->getMessage(),
            ]);
            $this->messageManager->addErrorMessage(
                __('Waffy payment could not be initiated. Please try again or choose a different payment method.'),
            );
            return $this->redirectFactory->create()->setPath('checkout/cart');
        }
    }

    private function buildCheckoutRequest(Order $order): CheckoutRequest
    {
        $storeId = (int) $order->getStoreId();

        $clientId     = $this->config->getClientId($storeId);
        $clientSecret = $this->config->getClientSecret($storeId);

        $billing   = $order->getBillingAddress();
        $phone     = $this->normalisePhone((string) ($billing?->getTelephone() ?? ''));
        $firstName = (string) ($billing?->getFirstname() ?? $order->getCustomerFirstname() ?? 'Customer');
        $lastName  = (string) ($billing?->getLastname() ?? $order->getCustomerLastname() ?? 'Guest');
        $customer = new CustomerInfo(
            phoneNumber: $phone,
            firstName:   $firstName,
            lastName:    $lastName,
        );

        // Build product info from order items
        $itemNames  = [];
        $itemImages = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $itemNames[] = $item->getName() . ' x' . (int) $item->getQtyOrdered();
        }
        $product = new ProductInfo(
            title:           $this->config->getStoreName($storeId) . ' - Order #' . $order->getIncrementId(),
            description:     implode(', ', $itemNames),
            images:          $itemImages,
            category:        $this->config->getCategory($storeId),
            returnPolicy:    $this->config->getReturnPolicy($storeId),
            returnFeePayee:  $this->config->getReturnFeePayee($storeId),
        );

        $deadlineDays = $this->config->getMilestoneDeadlineDays($storeId);
        $deadline     = (new \DateTimeImmutable())->modify("+{$deadlineDays} days")->format('Y-m-d\TH:i:s.000\Z');

        $milestone = new MilestoneInfo(
            amount:   (float) $order->getGrandTotal(),
            deadline: $deadline,
            currency: 'SAR',
        );

        $orderTotal    = (float) $order->getGrandTotal();
        $merchantPhone = $this->config->getMerchantPhone($storeId);
        $brokerPhone   = $this->config->getBrokerPhone($storeId);

        $parties = [
            new Party(phoneNumber: $phone,         role: 'CUSTOMER', amount: $orderTotal),
            new Party(phoneNumber: $merchantPhone, role: 'PROVIDER', amount: $orderTotal, isSender: true),
        ];

        if ($brokerPhone !== '') {
            $parties[] = new Party(phoneNumber: $brokerPhone, role: 'BROKER', amount: 0.0, arbitrator: true);
        }

        $returnUrl = $order->getStore()->getBaseUrl()
            . 'waffy/checkout/return?order_id=' . $order->getIncrementId();

        return new CheckoutRequest(
            clientId:            $clientId,
            clientSecret:        $clientSecret,
            clientAdminEmail:    $this->config->getClientAdminEmail($storeId),
            clientAdminPassword: $this->config->getClientAdminPassword($storeId),
            customer:            $customer,
            product:             $product,
            milestone:           $milestone,
            parties:             $parties,
            redirectUrl:         $returnUrl,
            paymentType:         $this->config->getPaymentType($storeId),
        );
    }

    /**
     * Attempt to normalise a local phone number to E.164.
     * For Saudi numbers: strip leading 0, prepend +966.
     * If it already starts with +, leave it as-is.
     *
     * TODO: improve or ask buyer to enter E.164 directly in checkout.
     */
    private function normalisePhone(string $phone): string
    {
        $phone = preg_replace('/[\s\-().]/', '', $phone);
        if ($phone === '') {
            return '+966000000000'; // placeholder — will fail validation at Waffy if not real
        }
        if (str_starts_with($phone, '+')) {
            return $phone;
        }
        if (str_starts_with($phone, '00')) {
            return '+' . substr($phone, 2);
        }
        // Assume Saudi local: 05XXXXXXXX → +9665XXXXXXXX
        if (str_starts_with($phone, '0')) {
            return '+966' . substr($phone, 1);
        }
        return '+966' . $phone;
    }
}
