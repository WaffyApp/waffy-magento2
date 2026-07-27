<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Exception;

use RuntimeException;

/**
 * Base class for every exception thrown by the Waffy SDK. Catch this in
 * platform adapters to handle SDK errors uniformly.
 */
class WaffyException extends RuntimeException
{
    /**
     * The decoded Waffy backend response body, when the failure carried one.
     * Subclasses that capture it (ApiException, AuthException) override this.
     *
     * @return array<string, mixed>|null
     */
    public function getResponseBody(): ?array
    {
        return null;
    }

    /**
     * A human-readable message extracted from the Waffy backend response,
     * suitable for surfacing to a merchant/buyer. Returns null when the
     * failure carried no parseable backend message (callers should fall back
     * to their own generic copy).
     */
    public function getUserMessage(): ?string
    {
        $body = $this->getResponseBody();

        return $body !== null ? self::extractMessage($body) : null;
    }

    /**
     * Pull the meaningful text out of the two error shapes the Waffy backend
     * returns:
     *   - OAuth2:     {"error":"invalid_grant","error_description":"..."}
     *   - API errors: {"error":{"message":"Validation error","subErrors":[{"message":"..."}]}}
     *
     * Nested validation messages (subErrors) win over the generic wrapper.
     *
     * @param array<string, mixed> $body
     */
    private static function extractMessage(array $body): ?string
    {
        // OAuth2 error shape.
        if (isset($body['error_description']) && is_string($body['error_description'])) {
            return $body['error_description'];
        }

        // Recursively collect any human-readable message fields (API validation
        // errors nest the specific reason under error.subErrors[].message, and
        // the exact key varies by validator, so we scan the common ones).
        $messages = [];
        $collect  = static function ($node) use (&$collect, &$messages): void {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if (
                    is_string($value) && $value !== ''
                    && in_array($key, ['message', 'defaultMessage', 'reason', 'detail', 'errorMessage'], true)
                ) {
                    $messages[] = $value;
                } elseif (is_array($value)) {
                    $collect($value);
                }
            }
        };
        $collect($body);

        if ($messages !== []) {
            $messages = array_values(array_unique($messages));
            // When we have specific messages, drop the generic "Validation error" wrapper.
            if (count($messages) > 1) {
                $specific = array_values(array_filter(
                    $messages,
                    static fn (string $m): bool => stripos($m, 'validation error') === false,
                ));
                if ($specific !== []) {
                    $messages = $specific;
                }
            }

            return implode('; ', $messages);
        }

        // Last-resort OAuth fallback (error code with no description).
        if (isset($body['error']) && is_string($body['error'])) {
            return $body['error'];
        }

        return null;
    }
}
