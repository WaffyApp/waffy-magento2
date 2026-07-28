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

    /**
     * Build a milestone whose deadline is $days from now, formatted in the exact
     * shape the Waffy API expects for the `deadLine` field
     * (ISO 8601 with millisecond precision and a Z suffix, e.g.
     * "2026-12-31T23:59:59.000Z").
     *
     * $now is injectable so the computed deadline is deterministically testable.
     *
     * @param AddOnFee[] $addOnFees
     */
    public static function inDays(
        float $amount,
        int $days,
        string $currency = 'SAR',
        array $addOnFees = [],
        ?\DateTimeImmutable $now = null,
    ): self {
        $deadline = ($now ?? new \DateTimeImmutable())
            ->modify("+{$days} days")
            ->format('Y-m-d\TH:i:s.000\Z');

        return new self($amount, $deadline, $currency, $addOnFees);
    }
}
