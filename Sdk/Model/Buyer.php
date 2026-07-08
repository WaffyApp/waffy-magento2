<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

use Waffy\Ecommerce\Exception\ValidationException;

/**
 * Buyer (customer) identity to be passed to Waffy when creating a payment.
 *
 * Waffy primarily identifies buyers by phone (KSA market — Mada / STC Pay /
 * Apple Pay all rely on phone). Email is recommended for receipts.
 */
final readonly class Buyer
{
    public function __construct(
        public string $phone,
        public ?string $email = null,
        public ?string $name = null,
        public ?string $waffyUserId = null,
    ) {
        if (!preg_match('/^\+\d{8,15}$/', $phone)) {
            throw new ValidationException(
                sprintf('Buyer phone must be E.164 (e.g. +966555555555), got "%s".', $phone),
            );
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(sprintf('Invalid buyer email: "%s".', $email));
        }
    }
}
