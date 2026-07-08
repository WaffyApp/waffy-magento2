<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Contract;

/**
 * Persistence seam for OAuth2 tokens obtained during checkout.
 *
 * The SDK calls this interface; the platform plugin (Magento, WooCommerce, …)
 * provides the concrete implementation using whatever storage is available
 * (Magento config table, WP options, DB, Redis, etc.).
 *
 * Expiry: all three token types are JWTs with an `exp` claim. The SDK checks
 * expiry before using a cached token and re-fetches when needed. Implementations
 * only need to store and retrieve — they do not need to manage TTL themselves.
 *
 * Security: tokens must be stored encrypted at rest. Never log or expose them.
 */
interface TokenStore
{
    /**
     * Persist the app token (client_credentials grant).
     * Shared per clientId — used to sign up customers (step 2).
     */
    public function storeAppToken(string $clientId, string $token): void;

    /** Return the cached app token, or null if not stored. */
    public function getAppToken(string $clientId): ?string;

    /**
     * Persist the merchant token (admin password grant).
     * Shared per clientId — used for all contract API calls (steps 4–7).
     */
    public function storeMerchantToken(string $clientId, string $token): void;

    /** Return the cached merchant token, or null if not stored. */
    public function getMerchantToken(string $clientId): ?string;

    /**
     * Persist the buyer token (customer password grant).
     * Keyed by clientUserId — one per buyer.
     */
    public function storeCustomerToken(string $clientUserId, string $token): void;

    /** Return the cached customer token, or null if not stored. */
    public function getCustomerToken(string $clientUserId): ?string;
}
