<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Auth;

/**
 * Contract for any authentication mechanism the SDK supports.
 *
 * v0.1 ships with BearerTokenAuthProvider (production-ready) and
 * OAuthProvider (scaffolded only — throws NotImplementedException).
 *
 * Implementations are responsible for returning the headers that should be
 * attached to every outbound request to the Waffy backend. Token refresh,
 * caching, and rotation are implementation details hidden behind this
 * interface so the WaffyClient never needs to know which auth flow is active.
 */
interface AuthProvider
{
    /**
     * Return the HTTP headers to attach to an outbound request.
     *
     * @return array<string, string>
     */
    public function getAuthHeaders(): array;

    /**
     * Stable identifier for the authenticated principal (merchant id, app id,
     * etc.) — used for logging, idempotency-key scoping, and audit trails.
     */
    public function getPrincipalId(): string;
}
