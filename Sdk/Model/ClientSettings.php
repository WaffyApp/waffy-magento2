<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

/**
 * Answer of `GET waffy-partner/client`: the settings of the client whose
 * token was used, read live.
 *
 * For startup configuration only. Waffy staff can change autoAcceptConsent at
 * any time, so per-buyer decisions should rely on the `consent` object of the
 * customer call, not on a cached copy of this.
 *
 * checkoutLoginMode — which buyer-credential shapes Waffy's checkout accepts:
 *                     'ticket', 'hint' (phone login_hint) or 'any'; kept as-is
 *                     when Waffy returns a mode this SDK does not know yet.
 */
class ClientSettings
{
    public function __construct(
        public readonly string $clientId,
        public readonly bool $autoAcceptConsent,
        public readonly ?string $checkoutLoginMode,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromResponse(array $body): self
    {
        $clientId  = $body['clientId']          ?? null;
        $loginMode = $body['checkoutLoginMode'] ?? null;

        return new self(
            clientId: is_string($clientId) ? $clientId : '',
            autoAcceptConsent: ($body['autoAcceptConsent'] ?? null) === true,
            checkoutLoginMode: is_string($loginMode) && $loginMode !== '' ? $loginMode : null,
        );
    }
}
