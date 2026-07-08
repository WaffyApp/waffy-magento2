<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

/**
 * Contract status values sent in Waffy webhook payloads.
 *
 * The "contractId" field in the webhook payload is actually the milestone ID.
 */
enum ContractStatus: string
{
    case CREATED             = 'CREATED';
    case PAYMENT_PROCESSING  = 'PAYMENT_PROCESSING';
    case PAID                = 'PAID';
    case ACCEPTED            = 'ACCEPTED';
    case CASHOUT_IN_PROGRESS = 'CASHOUT_IN_PROGRESS';
    case COMPLETED           = 'COMPLETED';
}
