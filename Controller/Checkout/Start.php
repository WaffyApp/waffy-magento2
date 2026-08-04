<?php

declare(strict_types=1);

namespace Waffy\Payment\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
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
use Waffy\Ecommerce\Exception\ValidationException;
use Waffy\Ecommerce\Support\PhoneNumber;
use Waffy\Payment\Model\Config;
use Waffy\Payment\Model\OrchestratorFactory;

/**
 * GET /waffy/checkout/start
 *
 * Called after the order is placed. Builds a CheckoutRequest from the last
 * order, calls the Waffy SDK, and hands back the Waffy payment URL.
 *
 * Two response modes off the same logic:
 *   - default            → HTTP redirect to the Waffy payment page (direct hit).
 *   - ?format=json       → JSON { url } so the checkout disclaimer modal can
 *                          prepare the link in the background and only enable its
 *                          "Continue to Waffy" button once the link is ready.
 * On failure the JSON mode returns { error: true, message } instead of the
 * redirect-to-cart-with-flash-message the direct mode uses.
 */
class Start implements HttpGetActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly JsonFactory $jsonFactory,
        private readonly RequestInterface $request,
        private readonly ManagerInterface $messageManager,
        private readonly Config $config,
        private readonly OrchestratorFactory $orchestratorFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(): \Magento\Framework\Controller\ResultInterface
    {
        $wantsJson = $this->request->getParam('format') === 'json';
        $order     = $this->checkoutSession->getLastRealOrder();

        if (!$order || !$order->getId()) {
            return $wantsJson
                ? $this->jsonFactory->create()->setData(['error' => true, 'message' => (string) __('Your order could not be found. Please try again.')])
                : $this->redirectFactory->create()->setPath('checkout/cart');
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

            if ($wantsJson) {
                return $this->jsonFactory->create()->setData(['url' => $finalUrl]);
            }

            $redirect = $this->redirectFactory->create();
            $redirect->setUrl($finalUrl);
            return $redirect;

        } catch (AuthException | ApiException | ValidationException $e) {
            $this->logger->error('Waffy checkout failed for order #' . $order->getIncrementId(), [
                'exception'    => $e->getMessage(),
                'responseBody' => $e->getResponseBody(),
                'previous'     => $e->getPrevious()?->getMessage(),
            ]);

            // Prefer the specific message the Waffy backend returned (e.g. an
            // invalid-phone validation error); for local ValidationExceptions the
            // message itself is already buyer-friendly. Fall back to generic copy.
            $backendMessage = $e->getUserMessage()
                ?? ($e instanceof ValidationException ? $e->getMessage() : null);
            $message = ($backendMessage !== null && $backendMessage !== '')
                ? (string) __('Waffy payment could not be initiated: %1', $backendMessage)
                : (string) __('Waffy payment could not be initiated. Please try again or choose a different payment method.');

            if ($wantsJson) {
                return $this->jsonFactory->create()->setData(['error' => true, 'message' => $message]);
            }

            $this->messageManager->addErrorMessage($message);
            return $this->redirectFactory->create()->setPath('checkout/cart');
        }
    }

    private function buildCheckoutRequest(Order $order): CheckoutRequest
    {
        $storeId = (int) $order->getStoreId();

        $clientId     = $this->config->getClientId($storeId);
        $clientSecret = $this->config->getClientSecret($storeId);

        $billing  = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();

        // Use the number the buyer entered for THIS order first. In Magento the
        // freshly-typed number lands on the shipping address; the billing address
        // can silently keep the customer's saved default (see order #61). Fall
        // back to billing, then to empty — we never fabricate a placeholder.
        $rawPhone = (string) ($shipping?->getTelephone() ?: $billing?->getTelephone() ?: '');
        $phone    = PhoneNumber::toE164($rawPhone);

        if ($phone === '') {
            throw new ValidationException(
                'A valid mobile number is required to pay with Waffy. '
                . 'Please add your phone number and try again.',
            );
        }

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

        $milestone = MilestoneInfo::inDays(
            amount: (float) $order->getGrandTotal(),
            days:   $this->config->getMilestoneDeadlineDays($storeId),
        );

        // Escrow party model (CUSTOMER / PROVIDER / optional BROKER) is owned by
        // the SDK — see Party::escrowSet. Merchant/broker phones come from config
        // and are expected to be E.164 already.
        $parties = Party::escrowSet(
            buyerPhone:    $phone,
            merchantPhone: $this->config->getMerchantPhone($storeId),
            brokerPhone:   $this->config->getBrokerPhone($storeId),
            amount:        (float) $order->getGrandTotal(),
        );

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
}
