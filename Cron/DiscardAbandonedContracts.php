<?php

declare(strict_types=1);

namespace Waffy\Payment\Cron;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Waffy\Payment\Model\CheckoutState;
use Waffy\Payment\Model\CheckoutSteps;

/**
 * Removes Waffy contracts that will never be paid (see etc/crontab.xml): those
 * whose removal failed before (cancelled OTP, cancelled order), and those left
 * unpaid for over a day.
 *
 * Best-effort. Waffy has no delete endpoint yet (TBD #23), so for now every
 * attempt is logged and the order stays flagged — the sweep is wired so that
 * the day the endpoint ships, nothing else has to change.
 */
class DiscardAbandonedContracts
{
    /** An unpaid contract older than this is removed. */
    private const ABANDONED_AFTER_SECONDS = 24 * 3600;

    public function __construct(
        private readonly CheckoutState $state,
        private readonly CheckoutSteps $steps,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::ABANDONED_AFTER_SECONDS);

        foreach ($this->state->contractsToDiscard($cutoff) as $row) {
            try {
                $order = $this->orderRepository->get((int) $row['order_id']);
                if ($order instanceof Order) {
                    $this->steps->discard($order);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Waffy: contract sweep skipped order ' . $row['order_id'] . ': ' . $e->getMessage());
            }
        }
    }
}
