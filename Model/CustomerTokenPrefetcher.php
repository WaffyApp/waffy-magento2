<?php

declare(strict_types=1);

namespace Waffy\Payment\Model;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Decides whether the logged-in shopper needs their Waffy token minted, and if so
 * hands the work to AfterResponse so they never wait for it.
 *
 * Shared by both triggers — customer_login and the first storefront page view of
 * a session (a shopper who arrives with a live cookie never fires customer_login).
 */
class CustomerTokenPrefetcher
{
    /** Session key holding the unix time before which we will not try again. */
    private const SESSION_KEY = 'waffy_token_prefetch_after';

    /**
     * Gap between attempts for one session.
     *
     * Deliberately short, because a repeat attempt is nearly free: once a token is
     * cached, ensureCustomerToken() is a table read and a JWT parse with no HTTP at
     * all. So this doubles as the retry interval after a failed prefetch and as a
     * proactive top-up once the cached token nears expiry — without needing to
     * record whether the last attempt succeeded (the deferred task runs after the
     * session is closed and could not record it anyway).
     */
    private const ATTEMPT_INTERVAL = 900;

    public function __construct(
        private readonly TokenWarmer $tokenWarmer,
        private readonly AfterResponse $afterResponse,
        private readonly CustomerSession $customerSession,
        private readonly StoreManagerInterface $storeManager,
    ) {}

    /**
     * Queue a prefetch for the currently logged-in customer, unless this session
     * has attempted one recently.
     */
    public function maybePrefetch(?int $customerId = null): void
    {
        $customerId ??= (int) $this->customerSession->getCustomerId();
        if ($customerId <= 0) {
            return; // guests have no identity to mint a token for
        }

        $after = (int) $this->customerSession->getData(self::SESSION_KEY);
        if ($after > time()) {
            return;
        }

        // Claim the window now, in the request, while the session is still open —
        // this is both the stampede guard and the failure back-off.
        $this->customerSession->setData(self::SESSION_KEY, time() + self::ATTEMPT_INTERVAL);

        $storeId = (int) $this->storeManager->getStore()->getId();

        $this->afterResponse->run(
            fn(): bool => $this->tokenWarmer->prefetchCustomerToken($customerId, $storeId)
        );
    }
}
