<?php

declare(strict_types=1);

namespace Waffy\Payment\Controller\Webhook;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Psr\Log\LoggerInterface;
use Waffy\Ecommerce\Exception\WebhookException;
use Waffy\Ecommerce\Model\WebhookEvent;
use Waffy\Ecommerce\Webhook\OrderAction;
use Waffy\Ecommerce\Webhook\WebhookIpAllowlist;
use Waffy\Ecommerce\Webhook\WebhookOutcome;
use Waffy\Payment\Model\Config;

/**
 * POST /waffy/webhook
 *
 * Thin Magento adapter over the SDK webhook logic. Responsibilities kept here
 * are platform glue only: HTTP handling, CSRF bypass, resolving the client IP,
 * locating the local order, and applying the outcome to a Magento order.
 *
 * The reusable business logic lives in the SDK:
 *   - {@see WebhookIpAllowlist} — request-origin check (webhooks are unsigned)
 *   - {@see WebhookEvent}       — parse the {contractId, status, referenceId} payload
 *   - {@see WebhookOutcome}     — status → platform-neutral action + comments
 *
 * The webhook `contractId` is the milestone ID stored in ext_order_id at checkout.
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Config $config,
        private readonly RemoteAddress $remoteAddress,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(): \Magento\Framework\Controller\ResultInterface
    {
        $json = $this->jsonFactory->create();

        $clientIp = (string) $this->remoteAddress->getRemoteAddress();
        if (!WebhookIpAllowlist::isAllowed($clientIp, $this->config->getWebhookAllowedIps())) {
            $this->logger->warning('Waffy webhook: request blocked by IP allowlist.', ['ip' => $clientIp]);
            return $json->setHttpResponseCode(403)->setData(['error' => 'Forbidden']);
        }

        $body    = (string) $this->request->getContent();
        $payload = json_decode($body, true);

        if (!is_array($payload)) {
            $this->logger->warning('Waffy webhook: invalid JSON payload.', ['body' => $body]);
            return $json->setHttpResponseCode(400)->setData(['error' => 'Invalid payload']);
        }

        $this->logger->info('Waffy webhook received.', ['payload' => $payload]);

        // Waffy webhooks are unsigned (confirmed 2026-06-28); origin is checked
        // via the IP allowlist above.
        try {
            $event = WebhookEvent::fromArray($payload);
        } catch (WebhookException $e) {
            $this->logger->warning('Waffy webhook: ' . $e->getMessage());
            return $json->setHttpResponseCode(422)->setData(['error' => $e->getMessage()]);
        }

        $order = $this->findOrderByMilestoneId($event->contractId);
        if ($order === null) {
            $this->logger->warning('Waffy webhook: no order found for contractId.', ['contractId' => $event->contractId]);
            // Return 200 so Waffy does not keep retrying for IDs we don't recognise.
            return $json->setData(['received' => true]);
        }

        if ($event->status === null) {
            $this->logger->warning('Waffy webhook: unknown status.', ['status' => $event->rawStatus]);
            return $json->setData(['received' => true]);
        }

        $this->applyOutcome($order, WebhookOutcome::forEvent($event));

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

    /**
     * Apply the SDK's platform-neutral outcome to a Magento order: map the
     * action onto Magento order state, then record the comments.
     */
    private function applyOutcome(Order $order, WebhookOutcome $outcome): void
    {
        if (
            $outcome->action === OrderAction::MARK_PAYMENT_SECURED
            && $order->getState() !== Order::STATE_PROCESSING
        ) {
            $order->setState(Order::STATE_PROCESSING)->setStatus('processing');
        }

        if ($outcome->adminComment !== '') {
            $order->addCommentToStatusHistory($outcome->adminComment, false, false);
        }
        if ($outcome->customerComment !== '') {
            $order->addCommentToStatusHistory($outcome->customerComment, false, true);
        }

        $this->orderRepository->save($order);
        $this->logger->info(
            'Waffy webhook: order #' . $order->getIncrementId() . ' updated (' . $outcome->action->name . ').',
        );
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
