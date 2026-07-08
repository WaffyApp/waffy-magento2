<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Exception;

use Throwable;

/**
 * Thrown when the Waffy backend returns a non-success HTTP status (4xx/5xx)
 * or the response body cannot be parsed.
 *
 * Carries enough context (status code, response body, request id) for the
 * caller to react — retries are handled inside the SDK, so this exception
 * means "the operation has definitively failed".
 */
final class ApiException extends WaffyException
{
    /**
     * @param array<string, mixed>|null $responseBody Decoded response body if available
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?array $responseBody = null,
        public readonly ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
