<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Orchestrator;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Waffy\Ecommerce\Contract\ProgressReporter;
use Waffy\Ecommerce\Contract\TokenStore;
use Waffy\Ecommerce\Dto\CheckoutRequest;
use Waffy\Ecommerce\Dto\CheckoutResult;
use Waffy\Ecommerce\Dto\CustomerInfo;
use Waffy\Ecommerce\Dto\MilestoneInfo;
use Waffy\Ecommerce\Dto\Party;
use Waffy\Ecommerce\Dto\ProductInfo;
use Waffy\Ecommerce\Exception\ApiException;
use Waffy\Ecommerce\Exception\AuthException;
use Waffy\Ecommerce\Progress\CheckoutStep;
use Waffy\Ecommerce\Progress\StepStatus;

/**
 * Orchestrates the 7-step Waffy checkout flow and returns a CheckoutResult.
 *
 * Flow:
 *   1a. POST {authBase}/oauth/token  — client_credentials (Basic clientId:secret) → appToken (JWT)
 *   1b. POST {authBase}/oauth/token  — password grant (admin email/pw)             → merchantToken (JWT)
 *   2.  POST {authBase}/v2/api/users/sign-up  — Bearer appToken                   → clientUserToken (opaque)
 *   3.  POST {authBase}/oauth/token  — password grant (buyer phone + clientUserToken) → customerToken (JWT)
 *   4.  POST {apiBase}/api/external/contracts            — Bearer merchantToken    → contractId
 *   5.  PATCH {apiBase}/api/external/contracts/{id}/milestones                    → milestoneId
 *   6.  PATCH {apiBase}/api/external/contracts/{id}/parties
 *   7.  GET   {apiBase}/api/external/contracts/startPayment/{milestoneId}/{clientId} → paymentUrl
 *
 * Returns CheckoutResult containing paymentUrl + customerToken (+ contractId + milestoneId).
 *
 * Token caching: every token is cached in the TokenStore and reused until it is
 * within a leeway window of its `exp` claim. None of the four auth calls above
 * has to happen during checkout if the cache is warm — see warmMerchantTokens()
 * (run it on a schedule) and ensureCustomerToken() (run it when the buyer logs in).
 */
class EcomCheckoutOrchestrator
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

    /** Total timeout for warm-up / prefetch calls (see warmHttpClient()). */
    public const WARM_TIMEOUT_SECONDS = 8.0;

    /** Connect timeout for warm-up / prefetch calls (see warmHttpClient()). */
    public const WARM_CONNECT_TIMEOUT_SECONDS = 5.0;

    private readonly ClientInterface $http;

    public function __construct(
        private readonly string $authBaseUrl,
        private readonly string $apiBaseUrl,
        private readonly TokenStore $tokenStore,
        ?ClientInterface $httpClient = null,
        private readonly ?ProgressReporter $progress = null,
    ) {
        $this->http = $httpClient ?? new GuzzleClient([
            'timeout'         => 30.0,
            'connect_timeout' => 10.0,
            'http_errors'     => true,
        ]);
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
     * Run the full 7-step checkout flow.
     *
     * Redirect the buyer to CheckoutResult::$paymentUrl after this returns.
     * Store CheckoutResult::$customerToken server-side (encrypted) — it is
     * needed for any future calls made on behalf of this buyer.
     *
     * @throws AuthException  on credential / sign-up / customer-login failure
     * @throws ApiException   on any contract API error
     */
    public function initiateCheckout(CheckoutRequest $request): CheckoutResult
    {
        try {
            return $this->runCheckout($request, useCachedTokens: true);
        } catch (ApiException | AuthException $e) {
            if (!$this->isUnauthorized($e)) {
                throw $e;
            }
            // A cached token can be revoked server-side before its exp claim
            // (credential rotation, new admin login). Retry once, bypassing
            // the cache, so one stale token cannot poison every checkout.
            return $this->runCheckout($request, useCachedTokens: false);
        }
    }

    /**
     * @throws AuthException
     * @throws ApiException
     */
    private function runCheckout(CheckoutRequest $request, bool $useCachedTokens): CheckoutResult
    {
        // ── Step 1: app token + merchant token (served from cache when still valid) ─
        $appToken = $this->ensureAppToken(
            $request->clientId,
            $request->clientSecret,
            $useCachedTokens,
        );

        $merchantToken = $this->ensureMerchantToken(
            $request->clientId,
            $request->clientSecret,
            $request->clientAdminEmail,
            $request->clientAdminPassword,
            $useCachedTokens,
        );

        // ── Steps 2–3: buyer sign-up + login → customerToken (JWT) ─────────
        // Served from cache when the buyer already has a live token — typically
        // minted when they logged into the storefront, so checkout does no auth
        // work at all. Only a cache miss pays for the two round trips.
        $customerToken = $this->ensureCustomerToken(
            $request->clientId,
            $request->clientSecret,
            $request->customer,
            appToken: $appToken,
            useCache: $useCachedTokens,
        );

        // ── Steps 4–7: the contract itself ──────────────────────────────────
        // Never cacheable: every order is a new contract, so these four always run.

        $contractId = $this->tracked(
            CheckoutStep::CreateContract,
            fn(): string => $this->createContract($merchantToken, $request->product),
        );

        $milestoneId = $this->tracked(
            CheckoutStep::CreateMilestone,
            fn(): string => $this->createMilestone($merchantToken, $contractId, $request->product, $request->milestone),
        );

        $this->tracked(
            CheckoutStep::AddParties,
            // addParties() returns nothing; the closure yields a value so the
            // generic tracked() helper has something to hand back.
            function () use ($merchantToken, $contractId, $milestoneId, $request): bool {
                $this->addParties($merchantToken, $contractId, $milestoneId, $request->parties);

                return true;
            },
        );

        $paymentUrl = $this->tracked(
            CheckoutStep::StartPayment,
            fn(): string => $this->startPayment(
                $merchantToken,
                $milestoneId,
                $request->clientId,
                $request->redirectUrl,
                $request->paymentType,
            ),
        );

        return new CheckoutResult(
            paymentUrl: $paymentUrl,
            customerToken: $customerToken,
            contractId: $contractId,
            milestoneId: $milestoneId,
        );
    }

    // ── Token warm-up (off the checkout path) ─────────────────────────────────

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
            $this->reportCached(CheckoutStep::AppToken);
        } else {
            $this->tokenStore->storeAppToken($clientId, $this->tracked(
                CheckoutStep::AppToken,
                fn(): string => $this->fetchAppToken($clientId, $clientSecret),
            ));
            $refreshed[] = 'app';
        }

        if ($this->isTokenValid($this->tokenStore->getMerchantToken($clientId), $leewaySeconds)) {
            $this->reportCached(CheckoutStep::MerchantToken);
        } else {
            $this->tokenStore->storeMerchantToken($clientId, $this->tracked(
                CheckoutStep::MerchantToken,
                fn(): string => $this->fetchMerchantToken($clientId, $clientSecret, $clientAdminEmail, $clientAdminPassword),
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
     * @throws AuthException When sign-up or the buyer's password grant fails.
     */
    public function ensureCustomerToken(
        string $clientId,
        string $clientSecret,
        CustomerInfo $customer,
        ?string $appToken = null,
        bool $useCache = true,
        int $leewaySeconds = self::CUSTOMER_LEEWAY_SECONDS,
    ): string {
        $clientUserId = $customer->resolveClientUserId();

        if ($useCache) {
            $cached = $this->tokenStore->getCustomerToken($clientUserId);
            if ($cached !== null && $this->isTokenValid($cached, $leewaySeconds)) {
                // One cache hit skips both buyer calls, so both are reported —
                // the display should show two steps satisfied, not one.
                $this->reportCached(CheckoutStep::CustomerSignUp);
                $this->reportCached(CheckoutStep::CustomerToken);

                return $cached;
            }
        }

        // Step 2 — sign up (idempotent: an existing buyer still gets a token back).
        $appToken ??= $this->ensureAppToken($clientId, $clientSecret, $useCache);

        $clientUserToken = $this->tracked(
            CheckoutStep::CustomerSignUp,
            fn(): string => $this->signUpCustomer(
                $appToken,
                $clientUserId,
                $customer->phoneNumber,
                $customer->firstName,
                $customer->lastName,
            ),
        );

        // Step 3 — buyer password grant, using the opaque step-2 token as password.
        $customerToken = $this->tracked(
            CheckoutStep::CustomerToken,
            fn(): string => $this->fetchCustomerToken(
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
    private function ensureAppToken(
        string $clientId,
        string $clientSecret,
        bool $useCache,
        int $leewaySeconds = self::CHECKOUT_LEEWAY_SECONDS,
    ): string {
        $cached = $useCache ? $this->tokenStore->getAppToken($clientId) : null;
        if ($cached !== null && $this->isTokenValid($cached, $leewaySeconds)) {
            $this->reportCached(CheckoutStep::AppToken);

            return $cached;
        }

        $token = $this->tracked(
            CheckoutStep::AppToken,
            fn(): string => $this->fetchAppToken($clientId, $clientSecret),
        );
        $this->tokenStore->storeAppToken($clientId, $token);

        return $token;
    }

    /** Cached-or-fetched merchant token (step 1b). @throws AuthException */
    private function ensureMerchantToken(
        string $clientId,
        string $clientSecret,
        string $clientAdminEmail,
        string $clientAdminPassword,
        bool $useCache,
        int $leewaySeconds = self::CHECKOUT_LEEWAY_SECONDS,
    ): string {
        $cached = $useCache ? $this->tokenStore->getMerchantToken($clientId) : null;
        if ($cached !== null && $this->isTokenValid($cached, $leewaySeconds)) {
            $this->reportCached(CheckoutStep::MerchantToken);

            return $cached;
        }

        $token = $this->tracked(
            CheckoutStep::MerchantToken,
            fn(): string => $this->fetchMerchantToken($clientId, $clientSecret, $clientAdminEmail, $clientAdminPassword),
        );
        $this->tokenStore->storeMerchantToken($clientId, $token);

        return $token;
    }

    // ── Flow steps & helpers ──────────────────────────────────────────────────
    // The four auth/customer steps (1a–3) are public so tooling can drive the
    // flow one step at a time for diagnostics; the contract steps (4–7) and the
    // HTTP/token helpers remain private.

    /**
     * Build an AuthException from a failed Guzzle auth/sign-up request,
     * capturing the full (untruncated) backend response body when one is
     * present so callers can surface a meaningful message via getUserMessage().
     * Guzzle truncates the body in $e->getMessage(), so we read it off the
     * response directly.
     */
    private function authError(string $context, GuzzleException $e): AuthException
    {
        $decoded = $e instanceof BadResponseException
            ? json_decode((string) $e->getResponse()->getBody(), true)
            : null;
        $body = is_array($decoded) ? $decoded : null;

        return new AuthException($context . ': ' . $e->getMessage(), responseBody: $body, previous: $e);
    }

    /**
     * Step 1a — OAuth2 client_credentials grant.
     * Auth: Basic(clientId, clientSecret) · Body: grant_type=client_credentials
     * Returns: appToken (JWT) — used only for signUpCustomer (step 2).
     */
    public function fetchAppToken(string $clientId, string $clientSecret): string
    {
        try {
            $response = $this->http->request('POST', $this->authUrl('/oauth/token'), [
                'auth'        => [$clientId, $clientSecret],
                'form_params' => ['grant_type' => 'client_credentials'],
                'headers'     => ['Accept' => 'application/json'],
            ]);
        } catch (GuzzleException $e) {
            throw $this->authError('Waffy client login failed', $e);
        }

        $data = $this->decode($response->getBody()->getContents());

        if (empty($data['access_token']) || !is_string($data['access_token'])) {
            throw new AuthException('Waffy client login response missing access_token.');
        }

        return $data['access_token'];
    }

    /**
     * Step 1b — OAuth2 password grant using merchant admin credentials.
     * Auth: Basic(clientId, clientSecret) · Body: grant_type=password, username=adminEmail, password=adminPassword
     * Returns: merchantToken (JWT) — used as Bearer for all API calls (steps 4–7).
     */
    public function fetchMerchantToken(string $clientId, string $clientSecret, string $clientAdminEmail, string $clientAdminPassword): string
    {
        try {
            $response = $this->http->request('POST', $this->authUrl('/oauth/token'), [
                'auth'        => [$clientId, $clientSecret],
                'form_params' => [
                    'grant_type' => 'password',
                    'username'   => $clientAdminEmail,
                    'password'   => $clientAdminPassword,
                ],
                'headers'     => ['Accept' => 'application/json'],
            ]);
        } catch (GuzzleException $e) {
            throw $this->authError('Waffy merchant admin login failed', $e);
        }

        $data = $this->decode($response->getBody()->getContents());

        if (empty($data['access_token']) || !is_string($data['access_token'])) {
            throw new AuthException('Waffy client login response missing access_token.');
        }

        return $data['access_token'];
    }

    /**
     * Step 2 — Register or retrieve the buyer.
     * Auth: Bearer merchantToken
     * Body: { clientUserId, phoneNumber, firstName, lastName }
     * Returns: data.clientUserToken (opaque short token used as password in step 3)
     *
     * Idempotent: existing users return preExistingUser=true, token is still provided.
     * Note: password field is intentionally omitted — Waffy manages it internally.
     */
    public function signUpCustomer(
        string $merchantToken,
        string $clientUserId,
        string $phoneNumber,
        string $firstName,
        string $lastName,
    ): string {
        try {
            $response = $this->http->request('POST', $this->authUrl('/v2/api/users/sign-up'), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $merchantToken,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'json' => [
                    'clientUserId' => $clientUserId,
                    'phoneNumber'  => $phoneNumber,
                    'firstName'    => $firstName,
                    'lastName'     => $lastName,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw $this->authError('Waffy customer sign-up failed', $e);
        }

        $data = $this->decode($response->getBody()->getContents());

        $clientUserToken = $data['data']['clientUserToken'] ?? null;
        if (!is_string($clientUserToken) || $clientUserToken === '') {
            throw new AuthException('Waffy sign-up response missing data.clientUserToken.');
        }

        return $clientUserToken;
    }

    /**
     * Step 3 — OAuth2 password grant for the buyer.
     * Auth: Basic(clientId, clientSecret)
     * Body: grant_type=password, username=<phone>, password=<clientUserToken>
     * Returns: access_token (JWT, ~5h TTL) — the customerToken used in step 7.
     */
    public function fetchCustomerToken(
        string $clientId,
        string $clientSecret,
        string $phoneNumber,
        string $clientUserToken,
    ): string {
        try {
            $response = $this->http->request('POST', $this->authUrl('/oauth/token'), [
                'auth'        => [$clientId, $clientSecret],
                'form_params' => [
                    'grant_type' => 'password',
                    'username'   => $phoneNumber,
                    'password'   => $clientUserToken,
                ],
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (GuzzleException $e) {
            throw $this->authError('Waffy customer login failed', $e);
        }

        $data = $this->decode($response->getBody()->getContents());

        if (empty($data['access_token']) || !is_string($data['access_token'])) {
            throw new AuthException('Waffy customer login response missing access_token.');
        }

        return $data['access_token'];
    }

    /**
     * Step 4 — POST /api/external/contracts
     * Auth: Bearer merchantToken
     * Returns: data.id (contractId)
     */
    private function createContract(string $merchantToken, ProductInfo $product): string
    {
        $response = $this->apiPost($merchantToken, '/api/external/contracts', [
            'type'               => 'COMPLEX_CONTRACT',
            'senderRole'         => 'PROVIDER',
            'itemDetail'         => [
                'title'       => $product->title,
                'description' => $product->description,
                'images'      => $product->images,
            ],
            'returnPolicy'       => $product->returnPolicy,
            'returnFeePayee'     => $product->returnFeePayee,
            'waffyTermsAccepted' => true,
            'category'           => $product->category,
        ]);

        $contractId = $response['data']['id'] ?? null;
        if (!is_string($contractId) || $contractId === '') {
            throw new ApiException('Waffy create-contract response missing data.id.', statusCode: 0, responseBody: $response);
        }

        return $contractId;
    }

    /**
     * Step 5 — PATCH /api/external/contracts/{contractId}/milestones
     * Auth: Bearer merchantToken
     * Returns: data.milestones[0].id (milestoneId)
     */
    private function createMilestone(
        string $merchantToken,
        string $contractId,
        ProductInfo $product,
        MilestoneInfo $milestone,
    ): string {
        $milestonePayload = [
            'type'               => 'MILESTONE_CONTRACT',
            'senderRole'         => 'PROVIDER',
            'itemDetail'         => [
                'title'       => $product->title,
                'description' => $product->description,
            ],
            'itemPrice'          => $milestone->amount,
            'currency'           => $milestone->currency,
            'returnPolicy'       => $product->returnPolicy,
            'returnFeePayee'     => $product->returnFeePayee,
            'deadLine'           => $milestone->deadline,
            'waffyTermsAccepted' => true,
        ];

        if (!empty($milestone->addOnFees)) {
            $milestonePayload['addOnFees'] = array_map(
                static fn($fee) => $fee->toArray(),
                $milestone->addOnFees,
            );
        }

        $response = $this->apiPatch(
            $merchantToken,
            '/api/external/contracts/' . $contractId . '/milestones',
            ['milestones' => [$milestonePayload]],
        );

        $milestoneId = $response['data']['milestones'][0]['id'] ?? null;
        if (!is_string($milestoneId) || $milestoneId === '') {
            throw new ApiException('Waffy create-milestone response missing data.milestones[0].id.', statusCode: 0, responseBody: $response);
        }

        return $milestoneId;
    }

    /**
     * Step 6 — PATCH /api/external/contracts/{contractId}/parties
     * Auth: Bearer merchantToken
     *
     * @param Party[] $parties
     */
    private function addParties(
        string $merchantToken,
        string $contractId,
        string $milestoneId,
        array $parties,
    ): void {
        $this->apiPatch(
            $merchantToken,
            '/api/external/contracts/' . $contractId . '/parties',
            [
                'mileStonesParties' => [
                    $milestoneId => array_map(static fn(Party $p) => $p->toArray(), $parties),
                ],
            ],
        );
    }

    /**
     * Step 7 — GET /api/external/contracts/startPayment/{milestoneId}/{clientId}
     * Auth: Bearer merchantToken · Returns: data (URL string)
     */
    private function startPayment(
        string $merchantToken,
        string $milestoneId,
        string $clientId,
        string $redirectUrl,
        string $paymentType,
    ): string {
        $path = '/api/external/contracts/startPayment/' . $milestoneId . '/' . $clientId;

        try {
            $response = $this->http->request('GET', $this->apiUrl($path), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $merchantToken,
                    'Accept'        => 'application/json',
                ],
                'query' => [
                    'redirectUrl' => $redirectUrl,
                    'paymentType' => $paymentType,
                ],
            ]);
        } catch (BadResponseException $e) {
            throw new ApiException(
                'Waffy start-payment failed: ' . $e->getMessage(),
                statusCode: $e->getResponse()->getStatusCode(),
                responseBody: json_decode((string) $e->getResponse()->getBody(), true) ?: null,
                previous: $e,
            );
        } catch (GuzzleException $e) {
            throw new ApiException('Waffy start-payment failed: ' . $e->getMessage(), statusCode: 0, previous: $e);
        }

        $data = $this->decode($response->getBody()->getContents());

        // Response: { "status": 200, "timestamp": "...", "data": "<url string>" }
        $paymentUrl = isset($data['data']) && is_string($data['data']) ? $data['data'] : null;

        if ($paymentUrl === null || $paymentUrl === '') {
            throw new ApiException('Waffy start-payment response missing data URL.', statusCode: 0, responseBody: $data);
        }

        return $paymentUrl;
    }

    // ── Progress instrumentation ─────────────────────────────────────────────

    /**
     * Run one API call, announcing it to the ProgressReporter before and after.
     *
     * Failures are reported and then rethrown untouched — the reporter observes
     * the flow, it never changes it.
     *
     * @template T
     * @param callable():T $call
     * @return T
     */
    private function tracked(CheckoutStep $step, callable $call): mixed
    {
        $this->progress?->report($step, StepStatus::Running, 0.0);

        $startedAt = microtime(true);

        try {
            $result = $call();
        } catch (\Throwable $e) {
            $this->progress?->report($step, StepStatus::Failed, $this->elapsedMs($startedAt));
            throw $e;
        }

        $this->progress?->report($step, StepStatus::Done, $this->elapsedMs($startedAt));

        return $result;
    }

    /** Announce a call that did NOT happen because the cache already had a token. */
    private function reportCached(CheckoutStep $step): void
    {
        $this->progress?->report($step, StepStatus::Cached, 0.0);
    }

    private function elapsedMs(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 1);
    }

    // ── Token helpers ────────────────────────────────────────────────────────

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
    private function isUnauthorized(ApiException|AuthException $e): bool
    {
        if ($e instanceof ApiException) {
            return in_array($e->statusCode, [401, 403], true);
        }

        $previous = $e->getPrevious();
        return $previous instanceof ClientException
            && in_array($previous->getResponse()->getStatusCode(), [401, 403], true);
    }

    // ── HTTP helpers ─────────────────────────────────────────────────────────

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function apiPost(string $token, string $path, array $body): array
    {
        try {
            $response = $this->http->request('POST', $this->apiUrl($path), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'json' => $body,
            ]);
        } catch (BadResponseException $e) {
            throw new ApiException(
                'Waffy API POST ' . $path . ' failed: ' . $e->getMessage(),
                statusCode: $e->getResponse()->getStatusCode(),
                responseBody: json_decode((string) $e->getResponse()->getBody(), true) ?: null,
                previous: $e,
            );
        } catch (GuzzleException $e) {
            throw new ApiException('Waffy API POST ' . $path . ' failed: ' . $e->getMessage(), statusCode: 0, previous: $e);
        }

        return $this->decode($response->getBody()->getContents());
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function apiPatch(string $token, string $path, array $body): array
    {
        try {
            $response = $this->http->request('PATCH', $this->apiUrl($path), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'json' => $body,
            ]);
        } catch (BadResponseException $e) {
            throw new ApiException(
                'Waffy API PATCH ' . $path . ' failed: ' . $e->getMessage(),
                statusCode: $e->getResponse()->getStatusCode(),
                responseBody: json_decode((string) $e->getResponse()->getBody(), true) ?: null,
                previous: $e,
            );
        } catch (GuzzleException $e) {
            throw new ApiException('Waffy API PATCH ' . $path . ' failed: ' . $e->getMessage(), statusCode: 0, previous: $e);
        }

        return $this->decode($response->getBody()->getContents());
    }

    private function authUrl(string $path): string
    {
        return rtrim($this->authBaseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function apiUrl(string $path): string
    {
        return rtrim($this->apiBaseUrl, '/') . '/' . ltrim($path, '/');
    }

    /** @return array<string, mixed> */
    private function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ApiException('Failed to decode Waffy response: ' . $e->getMessage(), statusCode: 0);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
