<?php

declare(strict_types=1);

namespace Waffy\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Waffy\Payment\Model\CustomerTokenPrefetcher;

/**
 * customer_login → mint the buyer's Waffy token now, so checkout does not have to.
 *
 * The work is deferred until after the response, so logging in stays as fast as
 * it was.
 */
class PrefetchCustomerTokenOnLogin implements ObserverInterface
{
    public function __construct(
        private readonly CustomerTokenPrefetcher $prefetcher,
    ) {}

    public function execute(Observer $observer): void
    {
        $customer = $observer->getEvent()->getData('customer');

        $customerId = (is_object($customer) && method_exists($customer, 'getId'))
            ? (int) $customer->getId()
            : 0;

        if ($customerId > 0) {
            $this->prefetcher->maybePrefetch($customerId);
        }
    }
}
