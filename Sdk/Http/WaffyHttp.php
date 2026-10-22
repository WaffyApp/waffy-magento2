<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Waffy\Ecommerce\Config\AuthMode;
use Waffy\Ecommerce\Exception\ApiException;
use Waffy\Ecommerce\Exception\AuthException;

/**
 * The Waffy hosts and the request plumbing every API class shares: URL
 * building, JSON bodies, response decoding and the exceptions a failed call
 * becomes.
 *
 * Built by EcomCheckoutOrchestrator and handed to the Api\* classes; not
 * meant to be constructed by platform code.
 */
class WaffyHttp
{
    /** Keycloak realm path shared by every Waffy ID endpoint. */
    public const KEYCLOAK_REALM_PATH = '/realms/waffy';

    /**
     * @param string $idBaseUrl Waffy ID (Keycloak) host; '' for a legacy organization.
     */
    public function __construct(
        private readonly string $authBaseUrl,
        private readonly string $apiBaseUrl,
        private readonly ClientInterface $http,
        private readonly string $idBaseUrl = '',
        public readonly AuthMode $authMode = AuthMode::Legacy,
    ) {
    }

    /** True when this organization talks to Waffy ID rather than the legacy auth host. */
    public function isKeycloak(): bool
    {
        return $this->authMode === AuthMode::Keycloak;
    }

    /**
     * Send a request with caller-built Guzzle options — for the OAuth grants,
     * whose form bodies and Basic auth do not fit the JSON helpers below.
     *
     * @param array<string, mixed> $options
     * @throws GuzzleException
     */
    public function request(string $method, string $url, array $options): ResponseInterface
    {
        return $this->http->request($method, $url, $options);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function apiPost(string $token, string $path, array $body): array
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

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function apiPatch(string $token, string $path, array $body): array
    {
        return $this->patchJson($token, $this->apiUrl($path), $path, $body);
    }

    /**
     * PATCH a JSON body to $url. $path only labels the error, so the same
     * helper serves the API host and the auth host.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function patchJson(string $token, string $url, string $path, array $body): array
    {
        try {
            $response = $this->http->request('PATCH', $url, [
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

    /**
     * POST a JSON body to a Waffy ID partner endpoint (`/customers`, `/otp/send`, …).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function partnerPost(string $orgToken, string $path, string $context, array $body): array
    {
        try {
            $response = $this->http->request('POST', $this->partnerUrl($path), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $orgToken,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'json' => $body,
            ]);
        } catch (GuzzleException $e) {
            throw $this->authError($context, $e);
        }

        return $this->decode($response->getBody()->getContents());
    }

    /**
     * GET a Waffy ID partner endpoint.
     *
     * @param array<string, string>|null $query
     * @return array<string, mixed>
     */
    public function partnerGet(string $orgToken, string $path, string $context, ?array $query = null): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $orgToken,
                'Accept'        => 'application/json',
            ],
        ];
        if ($query !== null) {
            $options['query'] = $query;
        }

        try {
            $response = $this->http->request('GET', $this->partnerUrl($path), $options);
        } catch (GuzzleException $e) {
            throw $this->authError($context, $e);
        }

        return $this->decode($response->getBody()->getContents());
    }

    /**
     * Build an AuthException from a failed Guzzle auth/sign-up request,
     * capturing the full (untruncated) backend response body when one is
     * present so callers can surface a meaningful message via getUserMessage().
     * Guzzle truncates the body in $e->getMessage(), so we read it off the
     * response directly.
     */
    public function authError(string $context, GuzzleException $e): AuthException
    {
        $decoded = $e instanceof BadResponseException
            ? json_decode((string) $e->getResponse()->getBody(), true)
            : null;
        $body = is_array($decoded) ? $decoded : null;

        return new AuthException(
            $context . ': ' . $e->getMessage(),
            responseBody: $body,
            previous: $e,
            statusCode: $e instanceof BadResponseException ? $e->getResponse()->getStatusCode() : 0,
        );
    }

    public function authUrl(string $path): string
    {
        return rtrim($this->authBaseUrl, '/') . '/' . ltrim($path, '/');
    }

    public function apiUrl(string $path): string
    {
        return rtrim($this->apiBaseUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * The OAuth2 token endpoint for this organization's auth mode. Every grant
     * in the flow — client credentials, the admin password grant and the buyer's
     * ticket exchange — goes to this one URL.
     */
    public function tokenUrl(): string
    {
        return $this->isKeycloak()
            ? $this->idUrl(self::KEYCLOAK_REALM_PATH . '/protocol/openid-connect/token')
            : $this->authUrl('/oauth/token');
    }

    private function idUrl(string $path): string
    {
        return rtrim($this->idBaseUrl, '/') . '/' . ltrim($path, '/');
    }

    /** A Waffy ID partner endpoint, e.g. `/otp/send`. */
    private function partnerUrl(string $path): string
    {
        return $this->idUrl(self::KEYCLOAK_REALM_PATH . '/waffy-partner' . $path);
    }

    /** @return array<string, mixed> */
    public function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ApiException('Failed to decode Waffy response: ' . $e->getMessage(), statusCode: 0);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
