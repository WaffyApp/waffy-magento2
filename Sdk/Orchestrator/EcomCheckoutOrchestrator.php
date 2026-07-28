<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Orchestrator;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Waffy\Ecommerce\Contract\TokenStore;
use Waffy\Ecommerce\Dto\CheckoutRequest;
use Waffy\Ecommerce\Dto\CheckoutResult;
use Waffy\Ecommerce\Dto\MilestoneInfo;
use Waffy\Ecommerce\Dto\Party;
use Waffy\Ecommerce\Dto\ProductInfo;
use Waffy\Ecommerce\Exception\ApiException;
use Waffy\Ecommerce\Exception\AuthException;
use Waffy\Ecommerce\Support\PhoneNumber;

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
 */
class EcomCheckoutOrchestrator
{
    private readonly ClientInterface $http;

    public function __construct(
        private readonly string $authBaseUrl,
        private readonly string $apiBaseUrl,
        private readonly TokenStore $tokenStore,
        ?ClientInterface $httpClient = null,
    ) {
        $this->http = $httpClient ?? new GuzzleClient([
            'timeout'         => 30.0,
            'connect_timeout' => 10.0,
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
        $appToken = $useCachedTokens ? $this->tokenStore->getAppToken($request->clientId) : null;
        if (!$this->isTokenValid($appToken)) {
            $appToken = $this->fetchAppToken($request->clientId, $request->clientSecret);
            $this->tokenStore->storeAppToken($request->clientId, $appToken);
        }

        $merchantToken = $useCachedTokens ? $this->tokenStore->getMerchantToken($request->clientId) : null;
        if (!$this->isTokenValid($merchantToken)) {
            $merchantToken = $this->fetchMerchantToken(
                $request->clientId,
                $request->clientSecret,
                $request->clientAdminEmail,
                $request->clientAdminPassword,
            );
            $this->tokenStore->storeMerchantToken($request->clientId, $merchantToken);
        }

        // ── Step 2: sign up customer → clientUserToken (opaque) ────────────
        // clientUserId falls back to the phone (digits only) so sign-up and login
        // always use the same identifier for the same buyer.
        $clientUserId = $request->customer->clientUserId
            ?? PhoneNumber::toClientUserId($request->customer->phoneNumber);

        $clientUserToken = $this->signUpCustomer(
            $appToken,
            $clientUserId,
            $request->customer->phoneNumber,
            $request->customer->firstName,
            $request->customer->lastName,
        );

        // ── Step 3: customer login → customerToken (JWT) ───────────────────
        // Uses phone as username and clientUserToken (from step 2) as password.
        $customerToken = $this->fetchCustomerToken(
            $request->clientId,
            $request->clientSecret,
            $request->customer->phoneNumber,
            $clientUserToken,
        );
        $this->tokenStore->storeCustomerToken($clientUserId, $customerToken);

        // ── Step 4: create contract ─────────────────────────────────────────
        $contractId = $this->createContract($merchantToken, $request->product);

        // ── Step 5: create milestone ────────────────────────────────────────
        $milestoneId = $this->createMilestone($merchantToken, $contractId, $request->product, $request->milestone);

        // ── Step 6: add parties ─────────────────────────────────────────────
        $this->addParties($merchantToken, $contractId, $milestoneId, $request->parties);

        // ── Step 7: get payment URL ─────────────────────────────────────────
        $paymentUrl = $this->startPayment(
            $merchantToken,
            $milestoneId,
            $request->clientId,
            $request->redirectUrl,
            $request->paymentType,
        );

        return new CheckoutResult(
            paymentUrl: $paymentUrl,
            customerToken: $customerToken,
            contractId: $contractId,
            milestoneId: $milestoneId,
        );
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

    // ── Token helpers ────────────────────────────────────────────────────────

    /**
     * Returns true if $jwt is a non-expired JWT with at least 60 seconds left.
     * Parses the `exp` claim from the payload segment without a JWT library.
     */
    private function isTokenValid(?string $jwt): bool
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

        // 60-second safety buffer to avoid using a token that expires mid-request
        return (int) $payload['exp'] > (time() + 60);
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
