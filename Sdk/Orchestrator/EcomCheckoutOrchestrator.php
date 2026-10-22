<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Orchestrator;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use Waffy\Ecommerce\Api\AuthApi;
use Waffy\Ecommerce\Api\ClientsApi;
use Waffy\Ecommerce\Api\ContractsApi;
use Waffy\Ecommerce\Api\PartnerApi;
use Waffy\Ecommerce\Auth\TokenCache;
use Waffy\Ecommerce\Config\AuthMode;
use Waffy\Ecommerce\Contract\ProgressReporter;
use Waffy\Ecommerce\Contract\TokenStore;
use Waffy\Ecommerce\Dto\CheckoutRequest;
use Waffy\Ecommerce\Dto\CheckoutResult;
use Waffy\Ecommerce\Dto\PreparedContract;
use Waffy\Ecommerce\Exception\ApiException;
use Waffy\Ecommerce\Exception\AuthException;
use Waffy\Ecommerce\Exception\NotImplementedException;
use Waffy\Ecommerce\Http\WaffyHttp;
use Waffy\Ecommerce\Progress\CheckoutStep;
use Waffy\Ecommerce\Progress\ProgressTracker;

/**
 * The SDK's entry point: wires the API classes to one HTTP client, token store
 * and progress reporter, and runs the Waffy checkout.
 *
 * Two auth modes, selected per organization — see Config\AuthMode. Steps 4–7
 * are identical in both; only the identity half differs.
 *
 * Flow (AuthMode::Legacy — the default):
 *   1a. POST {authBase}/oauth/token  — client_credentials (Basic clientId:secret) → appToken (JWT)
 *   1b. POST {authBase}/oauth/token  — password grant (admin email/pw)             → merchantToken (JWT)
 *   2.  POST {authBase}/v2/api/users/sign-up  — Bearer appToken                   → clientUserToken (opaque)
 *   3.  POST {authBase}/oauth/token  — password grant (buyer phone + clientUserToken) → customerToken (JWT)
 *   4.  POST {apiBase}/api/external/contracts            — Bearer merchantToken    → contractId
 *   5.  PATCH {apiBase}/api/external/contracts/{id}/milestones                    → milestoneId
 *   6.  PATCH {apiBase}/api/external/contracts/{id}/parties
 *   7.  GET   {apiBase}/api/external/contracts/startPayment/{milestoneId}/{clientId} → paymentUrl
 *
 * Flow (AuthMode::Keycloak), where {kc} = {idBase}/realms/waffy:
 *   1a. POST {kc}/protocol/openid-connect/token — client_credentials (Basic)   → orgToken (JWT)
 *   1b. POST {kc}/protocol/openid-connect/token — password grant, client id +
 *       secret in the body, username = admin's E.164 mobile                    → merchantToken (JWT)
 *   2.  POST {kc}/waffy-partner/customers — Bearer orgToken                    → waffyUserId + consent
 *       (optionally chaining the consent SMS; there is no step-3 buyer login)
 *   4–7. exactly as above, Bearer merchantToken
 *   8.  POST {kc}/protocol/openid-connect/token — ticket exchange, scoped to
 *       the milestone's shortId (from step 5)                                  → ticket
 *
 * Returns CheckoutResult containing paymentUrl + customerToken (+ contractId + milestoneId).
 * Redirect the buyer to CheckoutResult::paymentUrlWithBuyer(), which appends
 * `userTokenUrl` or `ticket` as the mode requires.
 *
 * Step by step, for buyer consent (Waffy ID). initiateCheckout() runs every
 * call in one go and so cannot stop for an OTP; a platform that collects one
 * drives the calls itself, in this dependency order:
 *
 *   prepareContract()         contract → milestone → parties      (admin token)
 *   partner->prepareCustomer() Link Customer                      (org token)
 *     └ consentRequired() → partner->sendOtp() → partner->verifyOtp()
 *   startPayment()            after prepareContract()
 *   paymentTicket()           after prepareContract() AND consent resolved
 *   CheckoutResult::withTicket() once startPayment() and paymentTicket() are both done
 *
 * The two branches are independent, so a platform can run them in parallel
 * (e.g. as separate browser requests) while the buyer types the code.
 *
 * Where everything else lives:
 *   - $tokens    (Auth\TokenCache)   — cached tokens, warmMerchantTokens(), ensureCustomerToken()
 *   - $auth      (Api\AuthApi)       — steps 1a–3 and 8, one call each; AuthApi::detectAuthMode()
 *   - $partner   (Api\PartnerApi)    — Waffy ID buyer registration and consent (OTP, link)
 *   - $contracts (Api\ContractsApi)  — steps 4–7, one call each
 *   - $clients   (Api\ClientsApi)    — client settings: setWebhookUrl()
 */
class EcomCheckoutOrchestrator
{
    /** Total timeout for warm-up / prefetch calls (see warmHttpClient()). */
    public const WARM_TIMEOUT_SECONDS = 8.0;

    /** Connect timeout for warm-up / prefetch calls (see warmHttpClient()). */
    public const WARM_CONNECT_TIMEOUT_SECONDS = 5.0;

    public readonly AuthApi $auth;
    public readonly TokenCache $tokens;
    public readonly PartnerApi $partner;
    public readonly ContractsApi $contracts;
    public readonly ClientsApi $clients;

    private readonly ProgressTracker $progress;
    private readonly bool $isKeycloak;

    /**
     * @param string   $idBaseUrl Waffy ID (Keycloak) host — Environment::idBaseUrl().
     *                            Required when $authMode is Keycloak, unused otherwise.
     * @param AuthMode $authMode  Which identity stack this organization is on.
     *                            Defaults to Legacy, so an existing caller that does
     *                            not set it behaves exactly as before.
     *
     * @throws AuthException When $authMode is Keycloak and $idBaseUrl is empty.
     */
    public function __construct(
        string $authBaseUrl,
        string $apiBaseUrl,
        TokenStore $tokenStore,
        ?ClientInterface $httpClient = null,
        ?ProgressReporter $progress = null,
        string $idBaseUrl = '',
        AuthMode $authMode = AuthMode::Legacy,
    ) {
        if ($authMode === AuthMode::Keycloak && $idBaseUrl === '') {
            throw new AuthException('EcomCheckoutOrchestrator: idBaseUrl is required when authMode is keycloak.');
        }

        $http = new WaffyHttp(
            $authBaseUrl,
            $apiBaseUrl,
            $httpClient ?? new GuzzleClient([
                'timeout'         => 30.0,
                'connect_timeout' => 10.0,
                'http_errors'     => true,
            ]),
            $idBaseUrl,
            $authMode,
        );

        $this->isKeycloak = $http->isKeycloak();
        $this->progress   = new ProgressTracker($progress);
        $this->auth       = new AuthApi($http);
        $this->tokens     = new TokenCache($this->auth, $tokenStore, $this->progress);
        $this->partner    = new PartnerApi($http, $this->tokens);
        $this->contracts  = new ContractsApi($http);
        $this->clients    = new ClientsApi($http, $this->tokens);
    }

    /**
     * HTTP client for the warm-up and prefetch paths.
     *
     * Checkout can afford to wait 30s for Waffy — the buyer is already committed
     * and the alternative is a failed order. A warm-up runs on a cron tick or a
     * storefront page view, where a hung request is worse than a skipped refresh
     * (the lazy fetch at checkout is still there as the backstop), so those paths
     * get a much tighter budget. The numbers live here rather than in each plugin
     * so all platforms share one policy.
     */
    public static function warmHttpClient(): ClientInterface
    {
        return new GuzzleClient([
            'timeout'         => self::WARM_TIMEOUT_SECONDS,
            'connect_timeout' => self::WARM_CONNECT_TIMEOUT_SECONDS,
            'http_errors'     => true,
        ]);
    }

    /**
     * Run the full checkout flow in one go.
     *
     * Redirect the buyer to CheckoutResult::paymentUrlWithBuyer() after this returns.
     * Store CheckoutResult::$customerToken server-side (encrypted) — it is
     * needed for any future calls made on behalf of this buyer.
     *
     * On Waffy ID this cannot pause for an OTP: when registration reports that
     * the buyer's consent is required it stops, before any contract is created,
     * and the platform has to use the step-by-step calls instead.
     *
     * Retries once with fresh tokens if Waffy rejects a cached one (401/403).
     *
     * @throws AuthException  on credential / sign-up / customer-login failure,
     *                        or when a Waffy ID buyer's consent is required
     * @throws ApiException   on any contract API error
     */
    public function initiateCheckout(CheckoutRequest $request): CheckoutResult
    {
        return TokenCache::retryOnUnauthorized(
            fn(bool $useCache): CheckoutResult => $this->runCheckout($request, $useCache),
        );
    }

    /**
     * Contract → milestone → parties: everything before start-payment and the
     * ticket. Independent of the buyer's registration and consent.
     *
     * Persist the result against the order; startPayment(), paymentTicket()
     * and discardContract() take it back.
     *
     * @throws AuthException
     * @throws ApiException
     */
    public function prepareContract(CheckoutRequest $request): PreparedContract
    {
        return TokenCache::retryOnUnauthorized(function (bool $useCache) use ($request): PreparedContract {
            $merchantToken = $this->merchantToken($request, $useCache);

            return $this->runPrepareContract($merchantToken, $request);
        });
    }

    /**
     * Step 7 — the payment page URL. Needs only the prepared contract.
     *
     * @throws AuthException
     * @throws ApiException
     */
    public function startPayment(CheckoutRequest $request, PreparedContract $contract): string
    {
        return TokenCache::retryOnUnauthorized(function (bool $useCache) use ($request, $contract): string {
            $merchantToken = $this->merchantToken($request, $useCache);

            return $this->runStartPayment($merchantToken, $request, $contract);
        });
    }

    /**
     * Step 8 (Waffy ID) — the buyer's single-use payment ticket for this contract.
     *
     * Call it only once the buyer's consent is resolved (verified, or not
     * required): the ticket is what lets them through to the payment page. It
     * lives about five minutes, so fetch it right before the redirect.
     *
     * @param string|null $paymentUrl Only needed when the milestone response
     *                                carried no shortId; it is then read from here.
     *
     * @throws AuthException On a legacy organization, or when Waffy refuses the
     *                       ticket (e.g. `consent_rejected`, `customer_not_party`).
     * @throws ApiException  When there is no shortId to scope the ticket to.
     */
    public function paymentTicket(CheckoutRequest $request, PreparedContract $contract, ?string $paymentUrl = null): string
    {
        if (!$this->isKeycloak) {
            throw new AuthException('paymentTicket() is a Waffy ID (keycloak) method: legacy organizations use the customer token.');
        }

        $shortId = $contract->shortId ?? ($paymentUrl !== null ? ContractsApi::shortIdFrom($paymentUrl) : null);
        if ($shortId === null) {
            throw new ApiException(
                'Waffy create-milestone response carried no shortId; pass the start-payment URL to scope the ticket.',
                statusCode: 0,
            );
        }

        return $this->progress->tracked(
            CheckoutStep::CustomerToken,
            fn(): string => $this->auth->fetchPaymentTicket(
                $request->clientId,
                $request->clientSecret,
                $request->customer->phoneNumber,
                $shortId,
            ),
        );
    }

    /**
     * Remove the unpaid contract of an order that died before payment.
     * Best-effort: callers log a failure and retry later, never block on it.
     *
     * @throws NotImplementedException Until Waffy ships the endpoint (see ContractsApi::deleteContract()).
     * @throws AuthException
     * @throws ApiException
     */
    public function discardContract(
        string $clientId,
        string $clientSecret,
        string $clientAdminUsername,
        string $clientAdminPassword,
        string $contractId,
    ): void {
        TokenCache::retryOnUnauthorized(function (bool $useCache) use ($clientId, $clientSecret, $clientAdminUsername, $clientAdminPassword, $contractId): void {
            $merchantToken = $this->tokens->ensureMerchantToken($clientId, $clientSecret, $clientAdminUsername, $clientAdminPassword, $useCache);
            $this->contracts->deleteContract($merchantToken, $contractId);
        });
    }

    /**
     * @throws AuthException
     * @throws ApiException
     */
    private function runCheckout(CheckoutRequest $request, bool $useCachedTokens): CheckoutResult
    {
        // ── Step 1: app token + merchant token (served from cache when still valid) ─
        $appToken = $this->tokens->ensureAppToken(
            $request->clientId,
            $request->clientSecret,
            $useCachedTokens,
        );

        $merchantToken = $this->merchantToken($request, $useCachedTokens);

        // ── Steps 2–3: the buyer ────────────────────────────────────────────
        // Legacy mints the buyer's token here and carries it to the payment page.
        // Served from cache when the buyer already has a live token — typically
        // minted when they logged into the storefront, so checkout does no auth
        // work at all. Only a cache miss pays for the two round trips.
        //
        // Keycloak only registers (and optionally asks consent of) the buyer at
        // this point: its payment ticket is scoped to a contract that does not
        // exist yet, so that half happens after the contract instead.
        $customerToken = '';
        $consent = null;

        if ($this->isKeycloak) {
            $registration = $this->progress->tracked(
                CheckoutStep::CustomerSignUp,
                fn() => $this->partner->registerCustomer($appToken, $request->customer, $request->consentMethod),
            );
            if ($registration->consentRequired()) {
                throw new AuthException(
                    'The buyer must consent to this organization first (OTP). initiateCheckout() cannot collect it: '
                    . 'use partner->sendOtp()/verifyOtp(), then prepareContract(), startPayment() and paymentTicket().',
                );
            }
            $consent = $registration->consent;
        } else {
            $customerToken = $this->tokens->ensureCustomerToken(
                $request->clientId,
                $request->clientSecret,
                $request->customer,
                appToken: $appToken,
                useCache: $useCachedTokens,
            );
        }

        // ── Steps 4–7: the contract itself ──────────────────────────────────
        // Never cacheable: every order is a new contract, so these always run.
        $contract = $this->runPrepareContract($merchantToken, $request);
        $paymentUrl = $this->runStartPayment($merchantToken, $request, $contract);

        if (!$this->isKeycloak) {
            return new CheckoutResult(
                paymentUrl: $paymentUrl,
                customerToken: $customerToken,
                contractId: $contract->contractId,
                milestoneId: $contract->milestoneId,
            );
        }

        // ── Step 8 (Keycloak only): the buyer's single-use payment ticket ───
        return CheckoutResult::withTicket(
            $contract,
            $paymentUrl,
            $this->paymentTicket($request, $contract, $paymentUrl),
            $consent,
        );
    }

    /** Steps 4–6 on a merchant token the caller already holds. */
    private function runPrepareContract(string $merchantToken, CheckoutRequest $request): PreparedContract
    {
        $contractId = $this->progress->tracked(
            CheckoutStep::CreateContract,
            fn(): string => $this->contracts->createContract($merchantToken, $request->product),
        );

        $contract = $this->progress->tracked(
            CheckoutStep::CreateMilestone,
            fn(): PreparedContract => $this->contracts->createMilestone($merchantToken, $contractId, $request->product, $request->milestone),
        );

        $this->progress->tracked(
            CheckoutStep::AddParties,
            // addParties() returns nothing; the closure yields a value so the
            // generic tracked() helper has something to hand back.
            function () use ($merchantToken, $contract, $request): bool {
                $this->contracts->addParties($merchantToken, $contract->contractId, $contract->milestoneId, $request->parties);

                return true;
            },
        );

        return $contract;
    }

    /** Step 7 on a merchant token the caller already holds. */
    private function runStartPayment(string $merchantToken, CheckoutRequest $request, PreparedContract $contract): string
    {
        return $this->progress->tracked(
            CheckoutStep::StartPayment,
            fn(): string => $this->contracts->startPayment(
                $merchantToken,
                $contract->milestoneId,
                $request->clientId,
                $request->redirectUrl,
                $request->paymentType,
            ),
        );
    }

    private function merchantToken(CheckoutRequest $request, bool $useCache): string
    {
        return $this->tokens->ensureMerchantToken(
            $request->clientId,
            $request->clientSecret,
            $request->clientAdminEmail,
            $request->clientAdminPassword,
            $useCache,
        );
    }
}
