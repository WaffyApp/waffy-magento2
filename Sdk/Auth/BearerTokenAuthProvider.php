<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Auth;

use Waffy\Ecommerce\Exception\AuthException;

/**
 * Auth provider that attaches a static Bearer token to every request.
 *
 * Used in v1.0 of the e-commerce plugins: the merchant pastes a long-lived
 * Waffy API token into the plugin admin and the plugin constructs this
 * provider with that token.
 *
 * The principal id is derived from the first segment of the token (if JWT) or
 * a hash of the token (if opaque) — never the token itself.
 */
final class BearerTokenAuthProvider implements AuthProvider
{
    private string $principalId;

    public function __construct(private readonly string $token)
    {
        if (trim($token) === '') {
            throw new AuthException('Bearer token cannot be empty.');
        }

        $this->principalId = $this->derivePrincipalId($token);
    }

    public function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->token,
        ];
    }

    public function getPrincipalId(): string
    {
        return $this->principalId;
    }

    private function derivePrincipalId(string $token): string
    {
        // If the token looks like a JWT (three base64url-encoded segments
        // separated by dots), decode the payload's `sub` claim. Otherwise
        // return a hash so we never log the raw token.
        $parts = explode('.', $token);
        if (count($parts) === 3) {
            $payload = $this->base64UrlDecode($parts[1]);
            if ($payload !== null) {
                $claims = json_decode($payload, true);
                if (is_array($claims) && isset($claims['sub']) && is_string($claims['sub'])) {
                    return $claims['sub'];
                }
            }
        }

        return 'token:' . substr(hash('sha256', $token), 0, 12);
    }

    private function base64UrlDecode(string $input): ?string
    {
        $remainder = strlen($input) % 4;
        if ($remainder !== 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($input, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
