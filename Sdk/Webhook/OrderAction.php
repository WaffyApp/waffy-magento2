<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Webhook;

/**
 * Platform-neutral action a webhook implies for the local order.
 *
 * The SDK decides the *meaning* of each Waffy status; each integration maps
 * these actions onto its own order-state model (e.g. Magento maps
 * MARK_PAYMENT_SECURED → Order::STATE_PROCESSING). Keeping the vocabulary
 * platform-neutral is what lets the same webhook logic drive Magento,
 * WooCommerce, Shopify, etc.
 */
enum OrderAction
{
    /** Informational only — record the comment(s) but do not change order state. */
    case NONE;

    /** Payment is secured in escrow — move the order to its "paid/processing" state. */
    case MARK_PAYMENT_SECURED;
}
