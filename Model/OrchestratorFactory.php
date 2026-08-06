<?php

declare(strict_types=1);

namespace Waffy\Payment\Model;

use Waffy\Ecommerce\Contract\TokenStore;
use Waffy\Ecommerce\Orchestrator\EcomCheckoutOrchestrator;

/**
 * Instantiates EcomCheckoutOrchestrator with the correct base URLs
 * read from Magento system config at call time.
 */
class OrchestratorFactory
{
    public function __construct(
        private readonly Config $config,
        private readonly TokenStore $tokenStore,
    ) {}

    public function create(?int $storeId = null): EcomCheckoutOrchestrator
    {
        return new EcomCheckoutOrchestrator(
            authBaseUrl: $this->config->getAuthBaseUrl($storeId),
            apiBaseUrl:  $this->config->getApiBaseUrl($storeId),
            tokenStore:  $this->tokenStore,
        );
    }

    /**
     * Same orchestrator on a tighter HTTP budget, for TokenWarmer's cron refresh
     * and login prefetch. Those paths would rather skip a refresh than hold a
     * PHP-FPM worker; checkout would rather wait than lose the order. Both budgets
     * are defined by the SDK.
     */
    public function createWarm(?int $storeId = null): EcomCheckoutOrchestrator
    {
        return new EcomCheckoutOrchestrator(
            authBaseUrl: $this->config->getAuthBaseUrl($storeId),
            apiBaseUrl:  $this->config->getApiBaseUrl($storeId),
            tokenStore:  $this->tokenStore,
            httpClient:  EcomCheckoutOrchestrator::warmHttpClient(),
        );
    }
}
