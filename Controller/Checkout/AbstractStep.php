<?php

declare(strict_types=1);

namespace Waffy\Payment\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Model\Order;
use Waffy\Payment\Model\CheckoutSteps;

/**
 * POST /waffy/checkout/{prepare,begin,verify,startPayment,ticket,go,cancel}
 *
 * One step of the Waffy checkout, called by the checkout modal after the order
 * is placed (see Model\CheckoutSteps for the order they run in).
 *
 * Every step acts only on the session's own last order — never on an order id
 * from the request — and releases the session lock before calling Waffy, so
 * the modal's parallel requests (prepare ∥ begin) are not serialised behind it.
 */
abstract class AbstractStep implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        protected readonly CheckoutSession $checkoutSession,
        protected readonly RequestInterface $request,
        protected readonly JsonFactory $jsonFactory,
        protected readonly CheckoutSteps $steps,
    ) {}

    /** @return array{ok: bool, status?: int, error?: string, message?: string|null} */
    abstract protected function run(Order $order): array;

    public function execute(): ResultInterface
    {
        $json  = $this->jsonFactory->create();
        $order = $this->checkoutSession->getLastRealOrder();
        $this->checkoutSession->writeClose();

        if (!$order || !$order->getId()) {
            return $json->setHttpResponseCode(404)->setData(['error' => 'not_found']);
        }

        $result = $this->run($order);
        if (($result['ok'] ?? false) !== true) {
            $json->setHttpResponseCode((int) ($result['status'] ?? 422));

            return $json->setData(['error' => $result['error'] ?? 'waffy_error', 'message' => $result['message'] ?? null]);
        }

        return $json->setData($result);
    }

    /** The JSON request body. @return array<string, mixed> */
    protected function body(): array
    {
        $decoded = json_decode((string) $this->request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * No form key: the step only ever acts on the caller's own session order,
     * so a forged request could do no more than the buyer's own page does.
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
