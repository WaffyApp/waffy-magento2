<?php

declare(strict_types=1);

namespace Waffy\Payment\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\FlagManager;
use Psr\Log\LoggerInterface;
use Waffy\Ecommerce\Dto\CustomerInfo;
use Waffy\Ecommerce\Exception\ValidationException;
use Waffy\Ecommerce\Exception\WaffyException;
use Waffy\Ecommerce\Support\PhoneNumber;

/**
 * Keeps the Waffy token cache warm so checkout never pays for an auth call.
 *
 * Two jobs, both off the checkout path:
 *
 *   1. Merchant tokens (app + merchant, shared by every shopper on a store) —
 *      refreshed ahead of expiry by Waffy\Payment\Cron\RefreshTokens, and primed
 *      when an admin saves the payment configuration. PHP has no process that
 *      survives between requests, so a cron tick is the closest thing to "fetch
 *      these once when the server starts" — and refreshing *before* expiry is
 *      what stops a shopper's request from ever waiting on Waffy.
 *
 *   2. The buyer's token — minted when the customer logs in, and on the first
 *      page of a session for a customer who arrived already logged in (that path
 *      never fires customer_login). By the time they place an order the token is
 *      cached and steps 2–3 of the checkout flow are skipped entirely.
 *
 * Everything here is best-effort. The lazy fetch inside the SDK's
 * initiateCheckout() is still the backstop, so a failed warm-up makes checkout
 * slower, never broken — and nothing here may surface an error to a shopper.
 */
class TokenWarmer
{
    /**
     * How long a successful customer prefetch suppresses further attempts.
     * Comfortably inside the ~5h life of a customer token, so the cached token is
     * still valid when the window lapses and we top it up again.
     */
    private const PREFETCH_TTL = 4 * 3600;

    /** Back-off after a failed prefetch, so a broken backend is not retried per page view. */
    private const PREFETCH_RETRY_AFTER = 900;

    /** Flag holding a hash of the credentials the cached tokens were minted under. */
    private const FLAG_CREDENTIALS_HASH = 'waffy_credentials_hash';

    public function __construct(
        private readonly Config $config,
        private readonly OrchestratorFactory $orchestratorFactory,
        private readonly TokenStore $tokenStore,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly FlagManager $flagManager,
        private readonly LoggerInterface $logger,
    ) {}

    // ── Merchant tokens ──────────────────────────────────────────────────────

    /**
     * Refresh a store's app + merchant tokens when either is near expiry.
     *
     * A no-op (two table reads, no HTTP) whenever both are still fresh, which is
     * what makes a frequent cron tick affordable.
     *
     * @return string[] Tokens refreshed ('app', 'merchant'); empty when both were
     *                  still valid or the store is not configured.
     */
    public function refreshMerchantTokens(?int $storeId = null): array
    {
        if (!$this->hasCompleteCredentials($storeId)) {
            return []; // gateway off, or the merchant has not finished setup
        }

        try {
            $refreshed = $this->orchestratorFactory->createWarm($storeId)->warmMerchantTokens(
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
     * Drop the token cache if — and only if — the credentials it was minted under
     * have changed.
     *
     * Tokens issued to a different Waffy client (or in the other environment) are
     * not merely stale but wrong, and the SDK only validates expiry, not identity,
     * so nothing else would catch them. The identity check matters because the
     * triggering event fires for *any* payment-section save: a merchant editing an
     * unrelated payment method must not cost every buyer their cached token.
     *
     * Only a hash is stored, never the credentials.
     *
     * @return bool True when the cache was dropped.
     */
    public function flushCacheIfCredentialsChanged(?int $storeId = null): bool
    {
        $hash = hash('sha256', implode('|', [
            $this->config->getEnvironment($storeId),
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

    // ── Customer token ───────────────────────────────────────────────────────

    /**
     * Mint and cache the buyer's Waffy token unless a live one is already cached.
     *
     * Skipped when we have no valid phone number for them: sign-up needs an E.164
     * number, and a customer who has never given one simply gets their token at
     * checkout, where the phone is a required field.
     *
     * @return bool True when a token is now cached for this customer.
     */
    public function prefetchCustomerToken(int $customerId, ?int $storeId = null): bool
    {
        if (!$this->hasCompleteCredentials($storeId)) {
            return false;
        }

        $customerInfo = $this->customerInfo($customerId);
        if ($customerInfo === null) {
            return false;
        }

        try {
            $this->orchestratorFactory->createWarm($storeId)->ensureCustomerToken(
                $this->config->getClientId($storeId),
                $this->config->getClientSecret($storeId),
                $customerInfo,
            );

            return true;
        } catch (WaffyException $e) {
            $this->logger->warning(
                'Waffy: customer token prefetch failed for customer #' . $customerId . ': ' . $e->getMessage(),
                ['storeId' => $storeId, 'responseBody' => $e->getResponseBody()],
            );

            return false;
        }
    }

    /** Seconds to suppress prefetching for a customer after a successful mint. */
    public function successWindow(): int
    {
        return self::PREFETCH_TTL;
    }

    /** Seconds to wait before retrying after a failed or impossible prefetch. */
    public function retryWindow(): int
    {
        return self::PREFETCH_RETRY_AFTER;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Build the SDK CustomerInfo for a Magento customer, or null when they have no
     * usable phone number.
     *
     * Mirrors Controller/Checkout/Start::buildCheckoutRequest() — the buyer's
     * telephone, here taken from their default billing address — so a prefetch and
     * a checkout resolve to the same buyer, and therefore the same cache key.
     * Never fabricates a placeholder number.
     */
    private function customerInfo(int $customerId): ?CustomerInfo
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException | LocalizedException $e) {
            return null;
        }

        $phone = PhoneNumber::toE164($this->defaultBillingTelephone($customer));
        if (!PhoneNumber::isValidE164($phone)) {
            return null;
        }

        try {
            return new CustomerInfo(
                phoneNumber: $phone,
                firstName:   (string) ($customer->getFirstname() ?: 'Customer'),
                lastName:    (string) ($customer->getLastname() ?: 'Guest'),
            );
        } catch (ValidationException $e) {
            return null; // belt and braces: CustomerInfo re-validates the number
        }
    }

    private function defaultBillingTelephone(CustomerInterface $customer): string
    {
        $defaultBillingId = $customer->getDefaultBilling();

        foreach ($customer->getAddresses() ?? [] as $address) {
            if ($defaultBillingId !== null && (string) $address->getId() === (string) $defaultBillingId) {
                return (string) $address->getTelephone();
            }
        }

        return '';
    }

    /**
     * True when the gateway is enabled and all four credentials are readable.
     * Warming with a missing secret would just log an auth failure every tick.
     */
    private function hasCompleteCredentials(?int $storeId): bool
    {
        return $this->config->isActive($storeId)
            && $this->config->getClientId($storeId) !== ''
            && $this->config->getClientSecret($storeId) !== ''
            && $this->config->getClientAdminEmail($storeId) !== ''
            && $this->config->getClientAdminPassword($storeId) !== '';
    }
}
