<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

/**
 * Snapshot of a Waffy escrow contract — what the merchant + buyer are bound
 * to.
 *
 * Used by SettlementTracker and webhook handlers when the SDK needs to
 * inspect the current state of a contract returned by the Waffy backend.
 */
readonly class Contract
{
    public function __construct(
        public string $contractId,
        public string $shortId,
        public EscrowStatus $status,
        public Money $amount,
    ) {
    }
}
