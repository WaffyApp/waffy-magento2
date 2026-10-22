<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Dto;

/**
 * How the buyer's identity reaches the Waffy hosted payment page.
 *
 * Both auth modes append one query parameter to the payment URL; only the name
 * and the value differ, so platform code can stay mode-agnostic by using
 * CheckoutResult::paymentUrlWithBuyer() instead of building the URL itself.
 *
 * param — `userTokenUrl` on AuthMode::Legacy, `ticket` on AuthMode::Keycloak.
 */
readonly class BuyerCredential
{
    public function __construct(
        public string $param,
        public string $value,
    ) {
    }
}
