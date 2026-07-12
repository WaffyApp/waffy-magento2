<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Dto;

use Waffy\Ecommerce\Exception\ValidationException;

/**
 * Single payment milestone attached to a complex contract.
 *
 * For a standard e-commerce purchase this is one milestone covering the full
 * order amount. Multi-milestone (instalment / staged delivery) flows can be
 * built by extending this in future.
 *
 * deadline  — ISO 8601 datetime, e.g. "2026-12-31T23:59:59.000Z".
 *             Waffy requires this field; use a date well in the future for
 *             on-demand purchases (e.g. +30 days from now).
 * addOnFees — optional platform fees added on top of the base itemPrice.
 */
readonly class MilestoneInfo
{
    /**
     * @param AddOnFee[] $addOnFees
     */
    public function __construct(
        public float $amount,
        public string $deadline,
        public string $currency = 'SAR',
        public array $addOnFees = [],
    ) {
        if ($amount <= 0) {
            throw new ValidationException('MilestoneInfo: amount must be greater than zero.');
        }
        if (trim($deadline) === '') {
            throw new ValidationException('MilestoneInfo: deadline cannot be empty.');
        }
    }
}
