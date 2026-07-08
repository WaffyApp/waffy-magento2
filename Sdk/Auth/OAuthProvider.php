<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Auth;

use Waffy\Ecommerce\Exception\NotImplementedException;

/**
 * OAuth 2.0 auth provider — scaffolded for v1.1+ once the Waffy Auth Service
 * supports third-party OAuth app registration with authorization-code flow.
 *
 * The constructor accepts the parameters we expect to need; the methods throw
 * NotImplementedException. Code that depends on the AuthProvider interface
 * can be written today without knowing this implementation isn't filled in
 * yet — swapping it in later requires no caller changes.
 *
 * Open TBDs blocking this (see plan §"Open TBDs"):
 *   - Confirm Waffy Auth Service supports third-party OAuth app registration
 *   - Token endpoint URL + scopes
 *   - Refresh-token rotation policy
 */
final class OAuthProvider implements AuthProvider
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $tokenEndpoint,
        private readonly string $refreshToken,
    ) {
    }

    public function getAuthHeaders(): array
    {
        throw new NotImplementedException(
            'OAuthProvider is scaffolded for v1.1. Use BearerTokenAuthProvider for v1.0.',
        );
    }

    public function getPrincipalId(): string
    {
        return 'oauth:' . $this->clientId;
    }
}
