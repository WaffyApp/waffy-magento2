<?php

declare(strict_types=1);

namespace Waffy\Payment\Observer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Waffy\Payment\Model\CustomerTokenPrefetcher;

/**
 * Storefront page view → make sure an already-logged-in shopper has a Waffy token.
 *
 * customer_login only fires when credentials are actually submitted; a shopper who
 * returns with a live session cookie never triggers it, which is the "opens the
 * website" case. Runs at most once per attempt window per session (see
 * CustomerTokenPrefetcher) and costs one session read otherwise.
 */
class PrefetchCustomerTokenOnVisit implements ObserverInterface
{
    public function __construct(
        private readonly CustomerTokenPrefetcher $prefetcher,
        private readonly CustomerSession $customerSession,
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->customerSession->isLoggedIn()) {
            return;
        }

        $this->prefetcher->maybePrefetch();
    }
}
