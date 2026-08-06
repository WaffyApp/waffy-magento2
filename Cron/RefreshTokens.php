<?php

declare(strict_types=1);

namespace Waffy\Payment\Cron;

use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;
use Waffy\Payment\Model\TokenWarmer;

/**
 * Keeps every store's Waffy app + merchant tokens fresh (see etc/crontab.xml).
 *
 * This is the module's answer to "fetch the tokens when the server starts": PHP
 * has no process that lives between requests, so instead of a bootstrap hook —
 * which would refresh only once a token had already expired, on some shopper's
 * page load — a cron tick refreshes ahead of expiry, on Magento's own worker.
 * When nothing is near expiry the whole job is a couple of table reads.
 *
 * Each store is warmed independently: credentials are store-scoped config, so a
 * multi-store install can point stores at different Waffy clients. Stores sharing
 * a clientId simply find the token already fresh and skip.
 */
class RefreshTokens
{
    public function __construct(
        private readonly TokenWarmer $tokenWarmer,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(): void
    {
        foreach ($this->storeRepository->getList() as $store) {
            if (!$store instanceof StoreInterface || !$store->getIsActive()) {
                continue;
            }

            $storeId = (int) $store->getId();
            if ($storeId === 0) {
                continue; // admin store — no storefront checkout happens here
            }

            try {
                $this->tokenWarmer->refreshMerchantTokens($storeId);
            } catch (\Throwable $e) {
                // One misconfigured store must not stop the others being warmed.
                $this->logger->warning(
                    'Waffy: token refresh cron failed for store ' . $storeId . ': ' . $e->getMessage()
                );
            }
        }
    }
}
