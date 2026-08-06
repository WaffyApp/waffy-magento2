<?php

declare(strict_types=1);

namespace Waffy\Payment\Observer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
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

    /**
     * Requests this observer must keep its hands off, because merely asking
     * whether a customer is logged in starts the session — and Magento holds the
     * session lock for a whole request. The progress endpoint is polled while
     * checkout is mid-flight and holding that lock, so touching the session here
     * would make every poll queue up behind checkout and arrive after it, which
     * is exactly what the live display must not do.
     */
    private const SESSION_FREE_ACTIONS = [
        'waffy_checkout_progress',
    ];

    public function execute(Observer $observer): void
    {
        $request = $observer->getEvent()->getData('request');

        if ($request instanceof RequestInterface
            && in_array($request->getFullActionName(), self::SESSION_FREE_ACTIONS, true)
        ) {
            return;
        }

        if (!$this->customerSession->isLoggedIn()) {
            return;
        }

        $this->prefetcher->maybePrefetch();
    }
}
