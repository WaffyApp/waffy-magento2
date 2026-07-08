<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

use Waffy\Ecommerce\Exception\ValidationException;

/**
 * Money value object — integer minor units (halalas for SAR, cents for USD)
 * plus an ISO-4217 currency code.
 *
 * Storing money as integers eliminates float rounding errors. Conversion to
 * major units (with decimals) is only done for display, never for math.
 *
 * Example:  Money::fromMajor('100.50', 'SAR') === Money(10050, 'SAR')
 */
final readonly class Money
{
    private const MINOR_UNIT_EXPONENT = [
        'SAR' => 2,
        'USD' => 2,
        'EUR' => 2,
        'AED' => 2,
        'KWD' => 3,
        'JOD' => 3,
        'BHD' => 3,
        'JPY' => 0,
    ];

    public function __construct(
        public int $minorUnits,
        public string $currency,
    ) {
        if ($minorUnits < 0) {
            throw new ValidationException('Money amount cannot be negative.');
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new ValidationException(
                sprintf('Currency must be a 3-letter ISO-4217 code, got "%s".', $currency),
            );
        }
    }

    /**
     * Construct from a major-units decimal string (e.g. "100.50").
     */
    public static function fromMajor(string $major, string $currency): self
    {
        $currency = strtoupper($currency);
        if (!preg_match('/^\d+(\.\d+)?$/', $major)) {
            throw new ValidationException(sprintf('Invalid major-units amount: "%s".', $major));
        }
        $exponent = self::MINOR_UNIT_EXPONENT[$currency] ?? 2;
        $parts = explode('.', $major);
        $whole = $parts[0];
        $frac  = str_pad($parts[1] ?? '', $exponent, '0');
        if (strlen($frac) > $exponent) {
            throw new ValidationException(
                sprintf('Amount "%s" has more decimals than allowed for %s (%d).', $major, $currency, $exponent),
            );
        }
        $minor = (int) ($whole . $frac);

        return new self($minor, $currency);
    }

    public function toMajor(): string
    {
        $exponent = self::MINOR_UNIT_EXPONENT[$this->currency] ?? 2;
        if ($exponent === 0) {
            return (string) $this->minorUnits;
        }
        $padded = str_pad((string) $this->minorUnits, $exponent + 1, '0', STR_PAD_LEFT);
        $whole = substr($padded, 0, -$exponent);
        $frac  = substr($padded, -$exponent);

        return $whole . '.' . $frac;
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits && $this->currency === $other->currency;
    }
}
