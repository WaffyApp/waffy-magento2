<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Client;

/**
 * Immutable value object wrapping a parsed Waffy API response.
 *
 * Orchestrators consume the decoded `data` payload; status code and headers
 * are exposed for callers that need them (idempotent-replay detection,
 * rate-limit reads, request-id propagation).
 */
final readonly class ApiResponse
{
    /**
     * @param array<string, mixed>          $data    Decoded JSON body
     * @param array<string, array<string>>  $headers Response headers (lowercased keys)
     */
    public function __construct(
        public int $statusCode,
        public array $data,
        public array $headers = [],
        public ?string $requestId = null,
    ) {
    }
}
