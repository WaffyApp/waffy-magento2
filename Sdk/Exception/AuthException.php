<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Exception;

use Throwable;

/**
 * Thrown when authentication fails — invalid token, expired credentials,
 * refresh failure, or missing required auth configuration.
 */
class AuthException extends WaffyException
{
    /**
     * @param array<string, mixed>|null $responseBody Decoded backend response body, when the failure carried one
     */
    public function __construct(
        string $message,
        public readonly ?array $responseBody = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }
}
