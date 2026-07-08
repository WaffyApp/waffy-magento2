<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

/**
 * Escrow contract status. Tracks the buyer's funds while held by Waffy and
 * after release to the merchant.
 *
 * Per Architecture doc §5.2 event mapping table.
 */
enum EscrowStatus: string
{
    case PENDING    = 'pending';     // contract created, awaiting payment
    case FUNDED     = 'funded';      // payment captured, funds in escrow
    case RELEASED   = 'released';    // funds released to merchant
    case REFUNDED   = 'refunded';    // refund processed back to buyer
    case DISPUTED   = 'disputed';    // dispute opened, funds locked
    case CANCELLED  = 'cancelled';   // contract cancelled before payment
}
