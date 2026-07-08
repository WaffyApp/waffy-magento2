<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Dto;

/**
 * Optional extra fee attached to a milestone.
 *
 * Example: a 10 SAR platform commission paid by the customer to the broker.
 *
 * beneficiary — phone number of the party that receives the fee.
 * payee       — phone number of the party that pays the fee.
 * type        — EXTRA_AMOUNT (most common for platform fees).
 * feeType     — FLAT (fixed SAR amount) or PERCENTAGE.
 */
final readonly class AddOnFee
{
    public function __construct(
        public float $amount,
        public string $description,
        public string $beneficiary,
        public string $payee,
        public string $type = 'EXTRA_AMOUNT',
        public string $feeType = 'FLAT',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'amount'      => $this->amount,
            'description' => $this->description,
            'beneficiary' => $this->beneficiary,
            'payee'       => $this->payee,
            'type'        => $this->type,
            'feeType'     => $this->feeType,
        ];
    }
}
