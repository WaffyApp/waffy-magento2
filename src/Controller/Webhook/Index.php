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
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * POST /waffy/webhook
 *
 * Receives contract status updates from Waffy and syncs the Magento order.
 *
 * Payload: { "contractId": "<milestoneId>", "status": "PAID|CASHOUT_IN_PROGRESS|COMPLETED|CREATED", "referenceId": "..." }
 * The "contractId" field in the Waffy webhook is actually the milestone ID stored in ext_order_id during checkout.
 *
 * Status → Magento order transition:
 *   CREATED             → comment only (order already pending)
 *   PAID                → processing  (payment secured in escrow)
 *   CASHOUT_IN_PROGRESS → comment only (keep processing)
 *   COMPLETED           → comment only (escrow released; merchant fulfils)
 *
 * TODO: add signature verification once Waffy confirms the signing key format.
 *       See project-docs/04-open-questions.md.
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(): \Magento\Framework\Controller\ResultInterface
    {
        $json    = $this->jsonFactory->create();
        $body    = (string) $this->request->getContent();
        $payload = json_decode($body, true);

        if (!is_array($payload)) {
            $this->logger->warning('Waffy webhook: invalid JSON payload.', ['body' => $body]);
            return $json->setHttpResponseCode(400)->setData(['error' => 'Invalid payload']);
        }

        $this->logger->info('Waffy webhook received.', ['payload' => $payload]);

        // Waffy webhooks are unsigned (confirmed 2026-06-28 via live capture).
        // No signature verification needed.

        $contractId  = (string) ($payload['contractId'] ?? '');
        $status      = strtoupper((string) ($payload['status'] ?? ''));
        $referenceId = (string) ($payload['referenceId'] ?? '');

        if ($contractId === '') {
            $this->logger->warning('Waffy webhook: missing contractId in payload.');
            return $json->setHttpResponseCode(422)->setData(['error' => 'Missing contractId']);
        }

        // contractId in the Waffy webhook = milestoneId stored in ext_order_id at checkout
        $order = $this->findOrderByMilestoneId($contractId);
        if ($order === null) {
            $this->logger->warning('Waffy webhook: no order found for contractId.', ['contractId' => $contractId]);
            // Return 200 so Waffy does not keep retrying for IDs we don't recognise
            return $json->setData(['received' => true]);
        }

        $this->handleStatus($order, $status, $contractId, $referenceId);

        return $json->setData(['received' => true]);
    }

    private function findOrderByMilestoneId(string $milestoneId): ?Order
    {
        $collection = $this->orderCollectionFactory->create()
            ->addFieldToFilter('ext_order_id', ['eq' => $milestoneId])
            ->setPageSize(1);

        /** @var Order $order */
        $order = $collection->getFirstItem();
        return $order->getId() ? $order : null;
    }

    private function handleStatus(Order $order, string $status, string $contractId, string $referenceId): void
    {
        $ref = $referenceId !== '' ? ' (ref: ' . $referenceId . ')' : '';

        match ($status) {
            'CREATED' => $this->addComment(
                $order,
                adminComment: 'Waffy: contract created' . $ref . '.',
            ),
            'PAYMENT_PROCESSING' => $this->addComment(
                $order,
                adminComment:    'Waffy: payment is being processed' . $ref . '.',
                customerComment: 'Your payment is being processed.',
            ),
            'PAID' => $this->transitionTo(
                $order,
                state:           Order::STATE_PROCESSING,
                status:          'processing',
                adminComment:    'Waffy: payment secured in escrow. Milestone: ' . $contractId . $ref,
                customerComment: 'Your payment has been received and secured.',
            ),
            'ACCEPTED' => $this->transitionTo(
                $order,
                state: Order::STATE_PROCESSING,
                status: 'processing',
                adminComment: 'Waffy: payment accepted, contract awaiting settlement' . $ref . '.',
                customerComment: 'Your payment has been confirmed.',
            ),
            'CASHOUT_IN_PROGRESS' => $this->addComment(
                $order,
                adminComment: 'Waffy: funds release in progress' . $ref . '.',
                customerComment: 'Your funds are being released.',
            ),
            'COMPLETED' => $this->addComment(
                $order,
                adminComment:    'Waffy: escrow completed, funds released to merchant' . $ref . '.',
                customerComment: 'Your order has been completed.',
            ),
            default => $this->logger->warning('Waffy webhook: unknown status.', ['status' => $status]),
        };
    }

    private function transitionTo(
        Order $order,
        string $state,
        string $status,
        string $adminComment,
        string $customerComment = '',
    ): void {
        if ($order->getState() !== $state) {
            $order->setState($state)->setStatus($status);
        }
        $order->addCommentToStatusHistory($adminComment, false, false);
        if ($customerComment !== '') {
            $order->addCommentToStatusHistory($customerComment, false, true);
        }
        $this->orderRepository->save($order);
        $this->logger->info('Waffy webhook: order #' . $order->getIncrementId() . ' → ' . $state . '.');
    }

    private function addComment(
        Order $order,
        string $adminComment,
        string $customerComment = '',
    ): void {
        $order->addCommentToStatusHistory($adminComment, false, false);
        if ($customerComment !== '') {
            $order->addCommentToStatusHistory($customerComment, false, true);
        }
        $this->orderRepository->save($order);
        $this->logger->info('Waffy webhook: comment added to order #' . $order->getIncrementId() . '.');
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
