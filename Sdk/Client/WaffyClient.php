<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Client;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Waffy\Ecommerce\Auth\AuthProvider;
use Waffy\Ecommerce\Exception\ApiException;

/**
 * Authenticated HTTP client for the Waffy backend.
 *
 * Wraps Guzzle, attaches auth headers via AuthProvider, applies retry +
 * exponential backoff to transient failures, and decodes JSON responses into
 * ApiResponse value objects. 4xx responses become ApiException; 5xx and
 * network errors are retried (per RetryEngine) and become ApiException only
 * if every retry fails.
 *
 * Designed to be testable: the underlying Guzzle ClientInterface can be
 * injected so unit tests use a MockHandler.
 */
class WaffyClient
{
    private readonly ClientInterface $http;
    private readonly LoggerInterface $logger;
    private readonly RetryEngine $retry;

    public function __construct(
        private readonly string $baseUrl,
        private readonly AuthProvider $auth,
        ?ClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
        ?RetryEngine $retry = null,
    ) {
        $this->http = $httpClient ?? new GuzzleClient([
            'timeout'         => 30.0,
            'connect_timeout' => 10.0,
            'http_errors'     => true,
        ]);
        $this->logger = $logger ?? new NullLogger();
        $this->retry  = $retry ?? new RetryEngine();
    }

    /**
     * @param array<string, scalar|null> $query
     */
    public function get(string $path, array $query = []): ApiResponse
    {
        return $this->send('GET', $path, ['query' => $query]);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function post(string $path, array $body, ?string $idempotencyKey = null): ApiResponse
    {
        return $this->send('POST', $path, $this->jsonOptions($body, $idempotencyKey));
    }

    /**
     * @param array<string, mixed> $body
     */
    public function patch(string $path, array $body, ?string $idempotencyKey = null): ApiResponse
    {
        return $this->send('PATCH', $path, $this->jsonOptions($body, $idempotencyKey));
    }

    /**
     * @param array<string, mixed> $body
     */
    public function put(string $path, array $body, ?string $idempotencyKey = null): ApiResponse
    {
        return $this->send('PUT', $path, $this->jsonOptions($body, $idempotencyKey));
    }

    public function delete(string $path): ApiResponse
    {
        return $this->send('DELETE', $path, []);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $path, array $options): ApiResponse
    {
        $url = $this->buildUrl($path);
        $headers = array_merge(
            $this->auth->getAuthHeaders(),
            $options['headers'] ?? [],
            ['Accept' => 'application/json'],
        );
        $options['headers'] = $headers;

        $principalId = $this->auth->getPrincipalId();
        $this->logger->debug('Waffy request', [
            'method'    => $method,
            'url'       => $url,
            'principal' => $principalId,
        ]);

        try {
            /** @var ResponseInterface $response */
            $response = $this->retry->execute(
                fn (): ResponseInterface => $this->http->request($method, $url, $options),
            );
        } catch (ClientException $e) {
            // 4xx — definitive failure, surface to caller as ApiException.
            throw $this->wrapClientError($e);
        } catch (RequestException $e) {
            // 5xx after retries exhausted, or other request error.
            throw $this->wrapRequestError($e);
        }

        return $this->parseResponse($response);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function jsonOptions(array $body, ?string $idempotencyKey): array
    {
        $options = [
            'json'    => $body,
            'headers' => [],
        ];
        if ($idempotencyKey !== null) {
            $options['headers']['Idempotency-Key'] = $idempotencyKey;
        }

        return $options;
    }

    private function buildUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function parseResponse(ResponseInterface $response): ApiResponse
    {
        $body = (string) $response->getBody();
        $data = $body === '' ? [] : $this->decodeJson($body);

        return new ApiResponse(
            statusCode: $response->getStatusCode(),
            data: $data,
            headers: array_change_key_case($response->getHeaders(), CASE_LOWER),
            requestId: $response->getHeaderLine('x-request-id') ?: null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ApiException(
                'Failed to decode Waffy response as JSON: ' . $e->getMessage(),
                statusCode: 0,
                previous: $e,
            );
        }
        if (!is_array($decoded)) {
            throw new ApiException(
                'Expected JSON object from Waffy, got: ' . gettype($decoded),
                statusCode: 0,
            );
        }

        return $decoded;
    }

    private function wrapClientError(ClientException $e): ApiException
    {
        $response = $e->getResponse();
        $body     = (string) $response->getBody();
        $parsed   = $body === '' ? null : json_decode($body, true);
        if (!is_array($parsed)) {
            $parsed = null;
        }

        $requestId = $response->getHeaderLine('x-request-id') ?: null;

        return new ApiException(
            'Waffy API error: ' . $e->getMessage(),
            statusCode: $response->getStatusCode(),
            responseBody: $parsed,
            requestId: $requestId,
            previous: $e,
        );
    }

    private function wrapRequestError(RequestException $e): ApiException
    {
        $response  = $e->getResponse();
        $status    = $response?->getStatusCode() ?? 0;
        $body      = $response !== null ? (string) $response->getBody() : '';
        $parsed    = $body === '' ? null : json_decode($body, true);
        if (!is_array($parsed)) {
            $parsed = null;
        }
        $requestId = $response?->getHeaderLine('x-request-id') ?: null;

        return new ApiException(
            'Waffy request failed: ' . $e->getMessage(),
            statusCode: $status,
            responseBody: $parsed,
            requestId: $requestId,
            previous: $e,
        );
    }
}
