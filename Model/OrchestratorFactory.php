<?php

declare(strict_types=1);

namespace Waffy\Payment\Model;

use Waffy\Ecommerce\Config\AuthMode;
use Waffy\Ecommerce\Contract\ProgressReporter;
use Waffy\Ecommerce\Contract\TokenStore;
use Waffy\Ecommerce\Orchestrator\EcomCheckoutOrchestrator;
use Waffy\Payment\Model\Setup\Connection;

/**
 * Builds the Waffy ID orchestrator from the merchant's connection — the hosts
 * come from their env file (system config), not from constants.
 */
class OrchestratorFactory
{
    public function __construct(
        private readonly Config $config,
        private readonly TokenStore $tokenStore,
    ) {}

    /**
     * @param ProgressReporter|null $progress Observes each API call — used to log the sequence.
     */
    public function create(?int $storeId = null, ?ProgressReporter $progress = null): EcomCheckoutOrchestrator
    {
        return new EcomCheckoutOrchestrator(
            authBaseUrl: $this->config->getAuthBaseUrl($storeId),
            apiBaseUrl:  $this->config->getApiBaseUrl($storeId),
            tokenStore:  $this->tokenStore,
            progress:    $progress,
            idBaseUrl:   $this->config->getIdBaseUrl($storeId),
            authMode:    AuthMode::Keycloak,
        );
    }

    /**
     * Same orchestrator on a tighter HTTP budget, for TokenWarmer's cron refresh.
     * That path would rather skip a refresh than hold a PHP-FPM worker; checkout
     * would rather wait than lose the order. Both budgets are defined by the SDK.
     */
    public function createWarm(?int $storeId = null, ?ProgressReporter $progress = null): EcomCheckoutOrchestrator
    {
        return new EcomCheckoutOrchestrator(
            authBaseUrl: $this->config->getAuthBaseUrl($storeId),
            apiBaseUrl:  $this->config->getApiBaseUrl($storeId),
            tokenStore:  $this->tokenStore,
            httpClient:  EcomCheckoutOrchestrator::warmHttpClient(),
            progress:    $progress,
            idBaseUrl:   $this->config->getIdBaseUrl($storeId),
            authMode:    AuthMode::Keycloak,
        );
    }

    /** For the setup wizard's checks: a connection that is not (yet) in config. */
    public function forConnection(Connection $connection): EcomCheckoutOrchestrator
    {
        return new EcomCheckoutOrchestrator(
            authBaseUrl: $connection->authBaseUrl,
            apiBaseUrl:  $connection->apiBaseUrl,
            tokenStore:  $this->tokenStore,
            httpClient:  EcomCheckoutOrchestrator::warmHttpClient(),
            idBaseUrl:   $connection->idBaseUrl,
            authMode:    AuthMode::Keycloak,
        );
    }
}
