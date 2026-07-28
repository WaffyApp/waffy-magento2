<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

/**
 * Contract/payment statuses Waffy sends in the webhook `status` field.
 *
 * The real inbound payload is `{ "contractId": "...", "status": "...",
 * "referenceId": "..." }` (confirmed from live captures 2026-06-28). These are
 * the statuses observed in production; unknown values resolve to null via
 * tryFrom() so adapters can log-and-ignore rather than crash.
 */
enum WebhookStatus: string
{
    case CREATED             = 'CREATED';
    case PAYMENT_PROCESSING  = 'PAYMENT_PROCESSING';
    case PAID                = 'PAID';
    case ACCEPTED            = 'ACCEPTED';
    case CASHOUT_IN_PROGRESS = 'CASHOUT_IN_PROGRESS';
    case COMPLETED           = 'COMPLETED';
}
