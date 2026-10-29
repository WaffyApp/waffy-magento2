<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Dto;

/**
 * A contract that has its milestone and parties, ready for start-payment and
 * the payment ticket — what EcomCheckoutOrchestrator::prepareContract()
 * returns. Persist it against the order: the later checkout calls, and
 * discardContract(), take it back.
 *
 * milestoneId — the key inbound webhooks arrive under (as their `contractId` field).
 * shortId     — the scope a payment ticket is issued for. Read from the
 *               create-milestone response; null on a backend that does not
 *               return it, in which case it is taken from the payment URL.
 */
class PreparedContract
{
    public function __construct(
        public readonly string $contractId,
        public readonly string $milestoneId,
        public readonly ?string $shortId = null,
    ) {
    }
}
