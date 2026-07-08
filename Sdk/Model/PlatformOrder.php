<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

use Waffy\Ecommerce\Exception\ValidationException;

/**
 * Platform-agnostic representation of an e-commerce order that the SDK
 * accepts and converts into a Waffy payment request.
 *
 * Magento builds one of these from a `\Magento\Sales\Model\Order`; WooCommerce
 * builds one from a `WC_Order`. Both adapters pass the same shape to
 * Layer 2, which keeps the core logic platform-independent.
 *
 * `platformOrderId` is the e-commerce platform's own order identifier (e.g.
 * Magento increment id "000000123" or WooCommerce id 456). Used for
 * idempotency scoping and for cross-referencing in webhook handlers.
 */
final readonly class PlatformOrder
{
    /**
     * @param list<OrderItem> $items
     * @param array<string, scalar|null> $metadata Free-form context passed to Waffy
     */
    public function __construct(
        public string $platformOrderId,
        public Money $total,
        public Buyer $buyer,
        public Merchant $merchant,
        public array $items = [],
        public array $metadata = [],
    ) {
        if ($platformOrderId === '') {
            throw new ValidationException('platformOrderId cannot be empty.');
        }
        if ($total->minorUnits === 0) {
            throw new ValidationException('Order total must be > 0.');
        }
        foreach ($items as $i => $item) {
            if (!$item instanceof OrderItem) {
                throw new ValidationException(sprintf('items[%d] must be OrderItem.', $i));
            }
            if ($item->unitPrice->currency !== $total->currency) {
                throw new ValidationException(
                    sprintf(
                        'Item currency (%s) does not match order currency (%s).',
                        $item->unitPrice->currency,
                        $total->currency,
                    ),
                );
            }
        }
    }
}
