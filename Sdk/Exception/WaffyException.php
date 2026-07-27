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
     *   - API errors: {"error":{"message":"Validation error","subErrors":[
     *                    {"field":"phoneNumber","rejectedValue":"+96650000",
     *                     "message":"doesn't seem to be a valid phone number!"}]}}
     *
     * Field-level validation errors (subErrors) win over the generic wrapper,
     * and we fold in the field + rejected value so the message names what was
     * wrong (e.g. "doesn't seem to be a valid phone number! (phoneNumber: +96650000)").
     *
     * @param array<string, mixed> $body
     */
    private static function extractMessage(array $body): ?string
    {
        // OAuth2 error shape.
        if (isset($body['error_description']) && is_string($body['error_description'])) {
            return $body['error_description'];
        }

        // API validation shape: error.subErrors[] carry the specific reason,
        // plus the field and the value the backend rejected.
        $error = $body['error'] ?? null;
        if (is_array($error)) {
            if (is_array($error['subErrors'] ?? null)) {
                $parts = [];
                foreach ($error['subErrors'] as $sub) {
                    if (!is_array($sub)) {
                        continue;
                    }
                    $msg = null;
                    foreach (['message', 'defaultMessage', 'reason', 'detail'] as $k) {
                        if (isset($sub[$k]) && is_string($sub[$k]) && $sub[$k] !== '') {
                            $msg = $sub[$k];
                            break;
                        }
                    }
                    if ($msg === null) {
                        continue;
                    }
                    $field    = (isset($sub['field']) && is_string($sub['field'])) ? $sub['field'] : null;
                    $rejected = $sub['rejectedValue'] ?? null;
                    if ($field !== null && is_scalar($rejected) && (string) $rejected !== '') {
                        $parts[] = sprintf('%s (%s: %s)', $msg, $field, (string) $rejected);
                    } elseif ($field !== null) {
                        $parts[] = sprintf('%s (%s)', $msg, $field);
                    } else {
                        $parts[] = $msg;
                    }
                }
                if ($parts !== []) {
                    return implode('; ', array_values(array_unique($parts)));
                }
            }

            // No usable subErrors — fall back to the top-level error message.
            if (isset($error['message']) && is_string($error['message']) && $error['message'] !== '') {
                return $error['message'];
            }
        }

        // Generic fallback: recursively collect any human-readable message fields
        // for shapes we don't explicitly know (the exact key varies by validator).
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
