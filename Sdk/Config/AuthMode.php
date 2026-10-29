<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Config;

/**
 * Which identity stack a Waffy organization authenticates against.
 *
 * Waffy is migrating organizations from the original auth host onto a
 * Keycloak realm ("Waffy ID"). The two differ in the token endpoint, in how
 * the client admin signs in, in how a buyer is registered, and in how the
 * buyer's credential reaches the hosted payment page — so an integration has
 * to know which one an org is on. Everything after the contract is created
 * (contracts, milestones, parties, startPayment) is identical.
 *
 * |                    | Legacy                                 | Keycloak                                              |
 * | ------------------ | -------------------------------------- | ----------------------------------------------------- |
 * | Token endpoint     | `{authBase}/oauth/token`               | `{idBase}/realms/waffy/protocol/openid-connect/token` |
 * | Client admin grant | password, Basic(client)                | password, client id/secret in the body                |
 * | Buyer registration | `POST {authBase}/v2/api/users/sign-up` | `POST {idBase}/realms/waffy/waffy-partner/customers`  |
 * | Buyer credential   | `customerToken` JWT → `&userTokenUrl=` | short-lived ticket → `&ticket=`                       |
 * | Buyer consent      | none                                   | SMS link / OTP, established per org+buyer             |
 *
 * `Legacy` is the default everywhere, so an integration that never sets a mode
 * keeps the behaviour it had before this existed.
 */
enum AuthMode: string
{
    case Legacy   = 'legacy';
    case Keycloak = 'keycloak';

    /**
     * Resolve a stored auth-mode string. Anything other than the explicit
     * 'keycloak' value resolves to Legacy, so an unset or unrecognised column on
     * an existing merchant row keeps working exactly as it did.
     */
    public static function fromString(?string $value): self
    {
        return $value === self::Keycloak->value ? self::Keycloak : self::Legacy;
    }
}
