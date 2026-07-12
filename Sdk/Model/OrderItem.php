<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

use Waffy\Ecommerce\Exception\ValidationException;

/**
 * Single line item from the platform's cart/order.
 *
 * Exposed for future use (per-item escrow rules, partial refunds, line-level
 * dispute resolution). v1.0 mostly just totals these into the contract
 * amount, but keeping them structured avoids a breaking change later.
 */
readonly class OrderItem
{
    public function __construct(
        public string $sku,
        public string $name,
        public int $quantity,
        public Money $unitPrice,
    ) {
        if ($quantity < 1) {
            throw new ValidationException('Item quantity must be >= 1.');
        }
        if ($sku === '') {
            throw new ValidationException('Item SKU cannot be empty.');
        }
        if ($name === '') {
            throw new ValidationException('Item name cannot be empty.');
        }
    }

    public function lineTotal(): Money
    {
        return new Money($this->unitPrice->minorUnits * $this->quantity, $this->unitPrice->currency);
    }
}
