<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Waffy\Ecommerce\Config\AuthMode;
use Waffy\Ecommerce\Exception\AuthException;
use Waffy\Ecommerce\Http\WaffyHttp;

/**
 * The identity provider: the OAuth grants and buyer sign-up (checkout steps
 * 1a–3, plus the Keycloak payment ticket, step 8).
 *
 * One HTTP call per method, nothing cached — Auth\TokenCache decides when a
 * call is needed. Public so tooling can drive the flow one step at a time for
 * diagnostics.
 */
class AuthApi
{
    /** Keycloak grant that exchanges an org's client credentials for a buyer's payment ticket. */
    public const TICKET_GRANT_TYPE = 'urn:waffy:params:oauth:grant-type:customer-token-exchange';

    public function __construct(private readonly WaffyHttp $http)
    {
    }

    public function authMode(): AuthMode
    {
        return $this->http->authMode;
    }

    /**
     * Work out which identity stack an organization's credentials belong to, by
     * asking each one for a client_credentials token and seeing which answers.
     *
     * Intended for the moment a merchant saves their credentials, so the result
     * can be stored against them and every later request goes straight to the
     * right host. Keycloak is tried first because it is where organizations are
     * heading; the legacy host is the fallback, which is also what keeps an
     * existing merchant working untouched.
     *
     * Pass EcomCheckoutOrchestrator::warmHttpClient() as $httpClient unless you
     * need your own: this runs on a merchant's request, not at checkout.
     *
     * @throws AuthException When neither stack accepts the credentials — the
     *   message carries both statuses, since "wrong secret" and "right secret,
     *   wrong stack" are otherwise indistinguishable.
     */
    public static function detectAuthMode(
        string $clientId,
        string $clientSecret,
        string $authBaseUrl,
        string $idBaseUrl,
        ClientInterface $httpClient,
    ): AuthMode {
        $probe = static function (string $url) use ($clientId, $clientSecret, $httpClient): int {
            try {
                $httpClient->request('POST', $url, [
                    'auth'        => [$clientId, $clientSecret],
                    'form_params' => ['grant_type' => 'client_credentials'],
                    'headers'     => ['Accept' => 'application/json'],
                ]);

                return 200;
            } catch (BadResponseException $e) {
                return $e->getResponse()->getStatusCode();
            } catch (GuzzleException) {
                return 0;
            }
        };

        $keycloakStatus = $probe(rtrim($idBaseUrl, '/') . WaffyHttp::KEYCLOAK_REALM_PATH . '/protocol/openid-connect/token');
        if ($keycloakStatus === 200) {
            return AuthMode::Keycloak;
        }

        $legacyStatus = $probe(rtrim($authBaseUrl, '/') . '/oauth/token');
        if ($legacyStatus === 200) {
            return AuthMode::Legacy;
        }

        throw new AuthException(
            'Waffy rejected these credentials on both identity stacks '
            . sprintf('(Waffy ID: HTTP %d, legacy auth: HTTP %d). ', $keycloakStatus, $legacyStatus)
            . 'Check the client ID and secret, and that the organization exists on this environment.',
        );
    }

    /**
     * Step 1a — OAuth2 client_credentials grant.
     * Auth: Basic(clientId, clientSecret) · Body: grant_type=client_credentials
     * Returns: appToken (JWT) — used for signUpCustomer / PartnerApi::registerCustomer (step 2).
     *
     * Identical in both auth modes apart from the endpoint: Keycloak serves it
     * from the realm's OpenID Connect token path instead of `/oauth/token`.
     */
    public function fetchAppToken(string $clientId, string $clientSecret): string
    {
        try {
            $response = $this->http->request('POST', $this->http->tokenUrl(), [
                'auth'        => [$clientId, $clientSecret],
                'form_params' => ['grant_type' => 'client_credentials'],
                'headers'     => ['Accept' => 'application/json'],
            ]);
        } catch (GuzzleException $e) {
            throw $this->http->authError('Waffy client login failed', $e);
        }

        $data = $this->http->decode($response->getBody()->getContents());

        if (empty($data['access_token']) || !is_string($data['access_token'])) {
            throw new AuthException('Waffy client login response missing access_token.');
        }

        return $data['access_token'];
    }

    /**
     * Step 1b — OAuth2 password grant using merchant admin credentials.
     * Returns: merchantToken (JWT) — used as Bearer for all API calls (steps 4–7).
     *
     * Legacy:   Auth Basic(clientId, clientSecret) · Body grant_type=password, username, password
     * Keycloak: no Authorization header · Body additionally carries client_id and
     *           client_secret (direct access grant against the realm), and
     *           $clientAdminEmail is the admin's E.164 mobile rather than an email.
     */
    public function fetchMerchantToken(string $clientId, string $clientSecret, string $clientAdminEmail, string $clientAdminPassword): string
    {
        $form = $this->http->isKeycloak()
            ? [
                'grant_type'    => 'password',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'username'      => $clientAdminEmail,
                'password'      => $clientAdminPassword,
            ]
            : [
                'grant_type' => 'password',
                'username'   => $clientAdminEmail,
                'password'   => $clientAdminPassword,
            ];

        $options = [
            'form_params' => $form,
            'headers'     => ['Accept' => 'application/json'],
        ];
        if (!$this->http->isKeycloak()) {
            $options['auth'] = [$clientId, $clientSecret];
        }

        try {
            $response = $this->http->request('POST', $this->http->tokenUrl(), $options);
        } catch (GuzzleException $e) {
            throw $this->http->authError('Waffy merchant admin login failed', $e);
        }

        $data = $this->http->decode($response->getBody()->getContents());

        if (empty($data['access_token']) || !is_string($data['access_token'])) {
            throw new AuthException('Waffy client login response missing access_token.');
        }

        return $data['access_token'];
    }

    /**
     * Step 2 — Register or retrieve the buyer.
     * Auth: Bearer appToken
     * Body: { clientUserId, phoneNumber, firstName, lastName }
     * Returns: data.clientUserToken (opaque short token used as password in step 3)
     *
     * Idempotent: existing users return preExistingUser=true, token is still provided.
     * Note: password field is intentionally omitted — Waffy manages it internally.
     */
    public function signUpCustomer(
        string $appToken,
        string $clientUserId,
        string $phoneNumber,
        string $firstName,
        string $lastName,
    ): string {
        try {
            $response = $this->http->request('POST', $this->http->authUrl('/v2/api/users/sign-up'), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $appToken,
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
            throw $this->http->authError('Waffy customer sign-up failed', $e);
        }

        $data = $this->http->decode($response->getBody()->getContents());

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
            $response = $this->http->request('POST', $this->http->authUrl('/oauth/token'), [
                'auth'        => [$clientId, $clientSecret],
                'form_params' => [
                    'grant_type' => 'password',
                    'username'   => $phoneNumber,
                    'password'   => $clientUserToken,
                ],
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (GuzzleException $e) {
            throw $this->http->authError('Waffy customer login failed', $e);
        }

        $data = $this->http->decode($response->getBody()->getContents());

        if (empty($data['access_token']) || !is_string($data['access_token'])) {
            throw new AuthException('Waffy customer login response missing access_token.');
        }

        return $data['access_token'];
    }

    /**
     * Step 8 (Keycloak) — exchange the org's client credentials for the buyer's
     * single-use payment ticket.
     * Auth: Basic(clientId, clientSecret)
     * Body: grant_type=<ticket grant>, login_hint=<buyer phone>, scope=payment:<shortId>
     * Returns: ticket (opaque, ~5 min) — appended to the payment URL as `&ticket=`.
     *
     * The ticket is scoped to one contract, so unlike the legacy customerToken it
     * is never cached: it is minted per checkout, after startPayment has revealed
     * the contract's shortId.
     */
    public function fetchPaymentTicket(
        string $clientId,
        string $clientSecret,
        string $phoneNumber,
        string $contractShortId,
    ): string {
        try {
            $response = $this->http->request('POST', $this->http->tokenUrl(), [
                'auth'        => [$clientId, $clientSecret],
                'form_params' => [
                    'grant_type' => self::TICKET_GRANT_TYPE,
                    'login_hint' => $phoneNumber,
                    'scope'      => 'payment:' . $contractShortId,
                ],
                'headers'     => ['Accept' => 'application/json'],
            ]);
        } catch (GuzzleException $e) {
            throw $this->http->authError('Waffy payment ticket request failed', $e);
        }

        $data = $this->http->decode($response->getBody()->getContents());

        $ticket = $data['ticket'] ?? null;
        if (!is_string($ticket) || $ticket === '') {
            throw new AuthException('Waffy payment ticket response missing ticket.');
        }

        return $ticket;
    }
}
