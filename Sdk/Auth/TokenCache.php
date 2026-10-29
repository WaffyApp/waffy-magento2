<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Auth;

use Waffy\Ecommerce\Api\AuthApi;
use Waffy\Ecommerce\Config\AuthMode;
use Waffy\Ecommerce\Contract\TokenStore;
use Waffy\Ecommerce\Dto\CustomerInfo;
use Waffy\Ecommerce\Exception\ApiException;
use Waffy\Ecommerce\Exception\AuthException;
use Waffy\Ecommerce\Progress\CheckoutStep;
use Waffy\Ecommerce\Progress\ProgressTracker;

/**
 * Every token the flow uses, served from the platform's TokenStore and fetched
 * through AuthApi only when the cached one is missing or about to expire.
 *
 * A token is reused until it is within a leeway window of its `exp` claim. None
 * of the four auth calls has to happen during checkout if the cache is warm —
 * see warmMerchantTokens() (run it on a schedule) and ensureCustomerToken()
 * (run it when the buyer logs in).
 */
class TokenCache
{
    /**
     * Remaining life a cached app/merchant token must have to be used mid-checkout.
     * Short, because these are only used for server-to-server calls we make within
     * the same request — a token that outlives the request is good enough.
     */
    public const CHECKOUT_LEEWAY_SECONDS = 60;

    /**
     * Remaining life a cached *customer* token must have to be handed to the buyer.
     * Much longer than CHECKOUT_LEEWAY_SECONDS because this token travels to the
     * Waffy-hosted payment page (`userTokenUrl`) and has to still be valid while
     * the buyer fills in their card details, not just while our request runs.
     */
    public const CUSTOMER_LEEWAY_SECONDS = 900;

    /**
     * Refresh-ahead window used by warmMerchantTokens(): a scheduled warm-up
     * refreshes anything expiring sooner than this, so a token never expires on a
     * customer-facing request. Must exceed the warm-up interval.
     */
    public const WARMUP_LEEWAY_SECONDS = 1800;

    public function __construct(
        private readonly AuthApi $auth,
        private readonly TokenStore $tokenStore,
        private readonly ProgressTracker $progress,
    ) {
    }

    /**
     * Run $call with cached tokens and, if Waffy rejects one (401/403), once
     * more bypassing the cache.
     *
     * A cached token can be revoked server-side before its exp claim
     * (credential rotation, new admin login); this stops one stale token from
     * poisoning every call until it expires on its own.
     *
     * @template T
     * @param callable(bool $useCache): T $call
     * @return T
     */
    public static function retryOnUnauthorized(callable $call): mixed
    {
        try {
            return $call(true);
        } catch (ApiException | AuthException $e) {
            if (!self::isUnauthorized($e)) {
                throw $e;
            }

            return $call(false);
        }
    }

    /**
     * Refresh the merchant-scoped tokens (app + merchant) if either is missing or
     * about to expire. Both are shared per clientId, so one warm-up serves every
     * shopper on the store.
     *
     * Designed to be called on a schedule (Magento cron, WP-Cron) and right after
     * a merchant saves their credentials. It is a no-op when both tokens still
     * have more than $leewaySeconds of life, so a frequent tick costs nothing but
     * two cache reads. PHP has no process that stays alive between requests, so a
     * recurring tick is the closest thing to "fetch these once when the server
     * comes up" — and unlike a bootstrap hook it refreshes *ahead* of expiry
     * instead of making some unlucky shopper's request pay for it.
     *
     * The lazy fetch inside initiateCheckout() remains the backstop: if the
     * schedule is not running at all, checkout still works, just slower.
     *
     * @return string[] Tokens actually refreshed: any of 'app', 'merchant'.
     *                  An empty array means both were still valid.
     *
     * @throws AuthException When Waffy rejects the credentials.
     */
    public function warmMerchantTokens(
        string $clientId,
        string $clientSecret,
        string $clientAdminEmail,
        string $clientAdminPassword,
        int $leewaySeconds = self::WARMUP_LEEWAY_SECONDS,
    ): array {
        $refreshed = [];

        if ($this->isTokenValid($this->tokenStore->getAppToken($clientId), $leewaySeconds)) {
            $this->progress->cached(CheckoutStep::AppToken);
        } else {
            $this->tokenStore->storeAppToken($clientId, $this->progress->tracked(
                CheckoutStep::AppToken,
                fn (): string => $this->auth->fetchAppToken($clientId, $clientSecret),
            ));
            $refreshed[] = 'app';
        }

        if ($this->isTokenValid($this->tokenStore->getMerchantToken($clientId), $leewaySeconds)) {
            $this->progress->cached(CheckoutStep::MerchantToken);
        } else {
            $this->tokenStore->storeMerchantToken($clientId, $this->progress->tracked(
                CheckoutStep::MerchantToken,
                fn (): string => $this->auth->fetchMerchantToken($clientId, $clientSecret, $clientAdminEmail, $clientAdminPassword),
            ));
            $refreshed[] = 'merchant';
        }

        return $refreshed;
    }

    /**
     * Return a usable customer token for $customer, minting one (sign-up + password
     * grant) only when the cache has nothing valid.
     *
     * Two callers, one code path:
     *   - checkout, where it is the buyer's token for the Waffy payment page;
     *   - the storefront login / first-page-view prefetch, which just wants the
     *     token in the cache before the buyer reaches checkout.
     *
     * Calling it repeatedly for the same buyer is cheap and harmless — a warm cache
     * short-circuits before any HTTP happens, which is what makes it safe to hang
     * off a login hook. Note that sign-up (step 2) creates a real Waffy user, so
     * only call this for a buyer you actually have an E.164 phone number for; never
     * speculatively for anonymous traffic.
     *
     * @param string|null $appToken Reuse an app token the caller already has;
     *                              fetched (and cached) on demand when null.
     * @param bool        $useCache Pass false to force a fresh mint — used by the
     *                              401/403 retry, where the cached token is suspect.
     *
     * @throws AuthException When sign-up or the buyer's password grant fails, or
     *                       on a Keycloak organization, which has no buyer token.
     */
    public function ensureCustomerToken(
        string $clientId,
        string $clientSecret,
        CustomerInfo $customer,
        ?string $appToken = null,
        bool $useCache = true,
        int $leewaySeconds = self::CUSTOMER_LEEWAY_SECONDS,
    ): string {
        if ($this->auth->authMode() === AuthMode::Keycloak) {
            throw new AuthException(
                'ensureCustomerToken() is a legacy-auth method: a Keycloak organization issues a '
                . 'contract-scoped payment ticket instead of a reusable buyer token. Use '
                . 'partner->prepareCustomer() to prefetch/establish consent, and let initiateCheckout() '
                . 'mint the ticket.',
            );
        }

        $clientUserId = $customer->resolveClientUserId();

        if ($useCache) {
            $cached = $this->tokenStore->getCustomerToken($clientUserId);
            if ($cached !== null && $this->isTokenValid($cached, $leewaySeconds)) {
                // One cache hit skips both buyer calls, so both are reported —
                // the display should show two steps satisfied, not one.
                $this->progress->cached(CheckoutStep::CustomerSignUp);
                $this->progress->cached(CheckoutStep::CustomerToken);

                return $cached;
            }
        }

        // Step 2 — sign up (idempotent: an existing buyer still gets a token back).
        $appToken ??= $this->ensureAppToken($clientId, $clientSecret, $useCache);

        $clientUserToken = $this->progress->tracked(
            CheckoutStep::CustomerSignUp,
            fn (): string => $this->auth->signUpCustomer(
                $appToken,
                $clientUserId,
                $customer->phoneNumber,
                $customer->firstName,
                $customer->lastName,
            ),
        );

        // Step 3 — buyer password grant, using the opaque step-2 token as password.
        $customerToken = $this->progress->tracked(
            CheckoutStep::CustomerToken,
            fn (): string => $this->auth->fetchCustomerToken(
                $clientId,
                $clientSecret,
                $customer->phoneNumber,
                $clientUserToken,
            ),
        );

        $this->tokenStore->storeCustomerToken($clientUserId, $customerToken);

        return $customerToken;
    }

    /** Cached-or-fetched app token (step 1a). @throws AuthException */
    public function ensureAppToken(
        string $clientId,
        string $clientSecret,
        bool $useCache = true,
        int $leewaySeconds = self::CHECKOUT_LEEWAY_SECONDS,
    ): string {
        $cached = $useCache ? $this->tokenStore->getAppToken($clientId) : null;
        if ($cached !== null && $this->isTokenValid($cached, $leewaySeconds)) {
            $this->progress->cached(CheckoutStep::AppToken);

            return $cached;
        }

        $token = $this->progress->tracked(
            CheckoutStep::AppToken,
            fn (): string => $this->auth->fetchAppToken($clientId, $clientSecret),
        );
        $this->tokenStore->storeAppToken($clientId, $token);

        return $token;
    }

    /** Cached-or-fetched merchant token (step 1b). @throws AuthException */
    public function ensureMerchantToken(
        string $clientId,
        string $clientSecret,
        string $clientAdminEmail,
        string $clientAdminPassword,
        bool $useCache = true,
        int $leewaySeconds = self::CHECKOUT_LEEWAY_SECONDS,
    ): string {
        $cached = $useCache ? $this->tokenStore->getMerchantToken($clientId) : null;
        if ($cached !== null && $this->isTokenValid($cached, $leewaySeconds)) {
            $this->progress->cached(CheckoutStep::MerchantToken);

            return $cached;
        }

        $token = $this->progress->tracked(
            CheckoutStep::MerchantToken,
            fn (): string => $this->auth->fetchMerchantToken($clientId, $clientSecret, $clientAdminEmail, $clientAdminPassword),
        );
        $this->tokenStore->storeMerchantToken($clientId, $token);

        return $token;
    }

    /**
     * Returns true if $jwt is a JWT with at least $leewaySeconds of life left.
     * Parses the `exp` claim from the payload segment without a JWT library.
     *
     * The leeway is the caller's call: a token only used server-side within this
     * request needs seconds, one handed to the buyer needs minutes, and a warm-up
     * refreshing ahead of expiry needs longer than its own tick interval.
     */
    private function isTokenValid(?string $jwt, int $leewaySeconds = self::CHECKOUT_LEEWAY_SECONDS): bool
    {
        if ($jwt === null || $jwt === '') {
            return false;
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return false;
        }

        // JWT payload is base64url-encoded JSON
        $decoded = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        if (!is_array($payload) || !isset($payload['exp']) || !is_numeric($payload['exp'])) {
            return false;
        }

        return (int) $payload['exp'] > (time() + $leewaySeconds);
    }

    /**
     * True when the failure is an HTTP 401/403 (token rejected by Waffy).
     * Verified 2026-07-01: dev API returns 401 for invalid/missing tokens;
     * 403 is included as a hedge in case revoked tokens are rejected there.
     */
    private static function isUnauthorized(ApiException|AuthException $e): bool
    {
        return in_array($e->statusCode, [401, 403], true);
    }
}
