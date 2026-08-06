<?php

declare(strict_types=1);

namespace Waffy\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Waffy\Payment\Model\TokenWarmer;

/**
 * Payment configuration saved in the admin → mint a fresh token pair immediately,
 * so the first checkout after setup is already warm.
 *
 * Note this event fires for *any* payment-section save, not just ours, which is
 * why the cache is only dropped when the Waffy credentials themselves changed.
 * Re-warming, on the other hand, is harmless either way: it is a no-op when the
 * cached tokens are still fresh.
 *
 * Runs inline: an admin saving a config section can wait a moment, and having the
 * credential error appear in the log immediately is useful to them.
 */
class PrimeTokensOnConfigSave implements ObserverInterface
{
    public function __construct(
        private readonly TokenWarmer $tokenWarmer,
    ) {}

    public function execute(Observer $observer): void
    {
        $this->tokenWarmer->flushCacheIfCredentialsChanged();

        // Store scope: config is saved per scope, but the tokens are shared per
        // clientId, so warming the default scope is enough to cover the common
        // single-store case. Other stores are picked up by the cron tick.
        $this->tokenWarmer->refreshMerchantTokens();
    }
}
