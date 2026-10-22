<?php

declare(strict_types=1);

namespace Waffy\Payment\Model;

use Magento\Framework\FlagManager;
use Psr\Log\LoggerInterface;
use Waffy\Ecommerce\Exception\WaffyException;

/**
 * Keeps the merchant's Waffy tokens warm so checkout never pays for an auth call.
 *
 * The org token and the client admin token are shared by every shopper on a
 * store. Waffy\Payment\Cron\RefreshTokens refreshes them ahead of expiry, and
 * the setup wizard primes them when a connection is activated. PHP has no
 * process that survives between requests, so a cron tick is the closest thing
 * to "fetch these once when the server starts".
 *
 * There is no buyer token to prefetch on Waffy ID: the payment ticket is scoped
 * to one contract, so it is minted at checkout.
 *
 * Everything here is best-effort. The lazy fetch inside each checkout step is
 * still the backstop, so a failed warm-up makes checkout slower, never broken.
 */
class TokenWarmer
{
    /** Flag holding a hash of the credentials the cached tokens were minted under. */
    private const FLAG_CREDENTIALS_HASH = 'waffy_credentials_hash';

    public function __construct(
        private readonly Config $config,
        private readonly OrchestratorFactory $orchestratorFactory,
        private readonly TokenStore $tokenStore,
        private readonly FlagManager $flagManager,
        private readonly CheckoutProgress $progress,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Refresh a store's org + admin tokens when either is near expiry.
     *
     * A no-op (two table reads, no HTTP) whenever both are still fresh, which is
     * what makes a frequent cron tick affordable.
     *
     * @return string[] Tokens refreshed ('app', 'merchant'); empty when both were
     *                  still valid or the store is not configured.
     */
    public function refreshMerchantTokens(?int $storeId = null): array
    {
        if (!$this->config->hasCompleteCredentials($storeId)) {
            return []; // gateway off, or the merchant has not finished setup
        }

        try {
            $refreshed = $this->orchestratorFactory
                ->createWarm($storeId, $this->progress->start('', 'warm-up'))
                ->tokens
                ->warmMerchantTokens(
                    $this->config->getClientId($storeId),
                    $this->config->getClientSecret($storeId),
                    $this->config->getClientAdminEmail($storeId),
                    $this->config->getClientAdminPassword($storeId),
                );

            if ($refreshed !== []) {
                $this->logger->info('Waffy: refreshed ' . implode(' + ', $refreshed) . ' token(s).', [
                    'storeId' => $storeId,
                ]);
            }

            return $refreshed;
        } catch (WaffyException $e) {
            // Wrong credentials, Waffy down, network blip — checkout still works
            // via the lazy fetch, so log and let the next tick try again.
            $this->logger->warning('Waffy: token refresh failed: ' . $e->getMessage(), [
                'storeId'      => $storeId,
                'responseBody' => $e->getResponseBody(),
            ]);

            return [];
        }
    }

    /**
     * Drop the token cache if — and only if — the connection it was minted under
     * has changed.
     *
     * Tokens issued to a different Waffy client (or another environment or host)
     * are not merely stale but wrong, and the SDK only validates expiry, not
     * identity, so nothing else would catch them. Only a hash is stored, never
     * the credentials.
     *
     * @return bool True when the cache was dropped.
     */
    public function flushCacheIfCredentialsChanged(?int $storeId = null): bool
    {
        $hash = hash('sha256', implode('|', [
            $this->config->getEnvironment($storeId),
            $this->config->getIdBaseUrl($storeId),
            $this->config->getClientId($storeId),
            $this->config->getClientSecret($storeId),
            $this->config->getClientAdminEmail($storeId),
            $this->config->getClientAdminPassword($storeId),
        ]));

        if ((string) $this->flagManager->getFlagData(self::FLAG_CREDENTIALS_HASH) === $hash) {
            return false;
        }

        $this->tokenStore->flush();
        $this->flagManager->saveFlag(self::FLAG_CREDENTIALS_HASH, $hash);

        return true;
    }
}
