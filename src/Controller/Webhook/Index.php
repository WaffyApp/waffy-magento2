<?php

declare(strict_types=1);

namespace Waffy\Payment\Controller\Webhook;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;

/**
 * POST /waffy/webhook/index
 *
 * Receives payment status updates from Waffy and updates the Magento order status.
 *
 * Waffy calls this endpoint twice per payment:
 *   - On success: { "status": "SUCCESS", "milestoneId": "...", "contractId": "...", "orderId": "..." }
 *   - On failure: { "status": "FAILURE", "milestoneId": "...", "contractId": "...", "orderId": "..." }
 *
 * The exact payload shape is TBD (see project-docs/04-open-questions.md).
 * TODO: implement webhook signature verification using Waffy\Ecommerce\Security\WebhookVerifier
 *       once Waffy backend confirms the signing key format (open question #5).
 *
 * URL to register in Waffy: https://your-store.com/waffy/webhook/index
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly OrderFactory $orderFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(): \Magento\Framework\Controller\ResultInterface
    {
        $json   = $this->jsonFactory->create();
        $body   = (string) $this->request->getContent();
        $payload = json_decode($body, true);

        if (!is_array($payload)) {
            $this->logger->warning('Waffy webhook: invalid JSON payload.', ['body' => $body]);
            return $json->setHttpResponseCode(400)->setData(['error' => 'Invalid payload']);
        }

        $this->logger->info('Waffy webhook received.', ['payload' => $payload]);

        // TODO: verify webhook signature (open question #5 in project-docs/04-open-questions.md)

        $status    = strtoupper((string) ($payload['status'] ?? ''));
        $incrementId = (string) ($payload['orderId'] ?? '');

        if ($incrementId === '') {
            $this->logger->warning('Waffy webhook: missing orderId in payload.');
            return $json->setHttpResponseCode(422)->setData(['error' => 'Missing orderId']);
        }

        $order = $this->orderFactory->create()->loadByIncrementId($incrementId);
        if (!$order->getId()) {
            $this->logger->warning('Waffy webhook: order not found.', ['orderId' => $incrementId]);
            return $json->setHttpResponseCode(404)->setData(['error' => 'Order not found']);
        }

        match ($status) {
            'SUCCESS' => $this->handleSuccess($order, $payload),
            'FAILURE' => $this->handleFailure($order, $payload),
            default   => $this->logger->warning('Waffy webhook: unknown status.', ['status' => $status]),
        };

        return $json->setData(['received' => true]);
    }

    private function handleSuccess(Order $order, array $payload): void
    {
        if ($order->getState() === Order::STATE_PROCESSING) {
            return; // already handled
        }

        $order->setState(Order::STATE_PROCESSING)
              ->setStatus('processing');
        $order->addCommentToStatusHistory(
            __('Waffy payment confirmed. Contract: %1, Milestone: %2',
                $payload['contractId'] ?? 'n/a',
                $payload['milestoneId'] ?? 'n/a'),
        );
        $this->orderRepository->save($order);

        $this->logger->info('Waffy webhook: order #' . $order->getIncrementId() . ' → processing.');
    }

    private function handleFailure(Order $order, array $payload): void
    {
        if ($order->getState() === Order::STATE_CANCELED) {
            return; // already handled
        }

        $order->setState(Order::STATE_CANCELED)
              ->setStatus('canceled');
        $order->addCommentToStatusHistory(
            __('Waffy payment failed or was rejected. Contract: %1, Milestone: %2',
                $payload['contractId'] ?? 'n/a',
                $payload['milestoneId'] ?? 'n/a'),
        );
        $this->orderRepository->save($order);

        $this->logger->info('Waffy webhook: order #' . $order->getIncrementId() . ' → canceled.');
    }

    // ── CsrfAwareActionInterface ─────────────────────────────────────────────
    // Waffy POSTs without a Magento form key — bypass CSRF for this endpoint only.

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
