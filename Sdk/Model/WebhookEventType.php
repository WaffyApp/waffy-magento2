<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

/**
 * Webhook event types the SDK knows how to route.
 *
 * The exact strings are TBD with backend dev (see plan §"Open TBDs" item 3).
 * Names below follow the Architecture doc §5.2 event mapping table; if the
 * backend uses different strings, update the case values without changing
 * callers (handlers register against this enum, not strings).
 */
enum WebhookEventType: string
{
    case CONTRACT_CREATED            = 'CONTRACT_CREATED';
    case PAYMENT_CREATED             = 'PAYMENT_CREATED';
    case PAYMENT_PROCESSING          = 'PAYMENT_PROCESSING';
    case PAYMENT_COMPLETED           = 'PAYMENT_COMPLETED';
    case PAYMENT_FAILED              = 'PAYMENT_FAILED';
    case ESCROW_RELEASED             = 'ESCROW_RELEASED';
    case SETTLEMENT_PAID             = 'SETTLEMENT_PAID';
    case DISPUTE_OPENED              = 'DISPUTE_OPENED';
    case DISPUTE_RESOLVED            = 'DISPUTE_RESOLVED';
    case REFUND_PROCESSED            = 'REFUND_PROCESSED';
}
