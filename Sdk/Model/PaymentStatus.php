<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

/**
 * Payment lifecycle status as exposed by the Waffy backend.
 *
 * Per developer-portal docs/api/payments page:
 *   pending → processing → completed | failed
 */
enum PaymentStatus: string
{
    case PENDING    = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED  = 'completed';
    case FAILED     = 'failed';
}
