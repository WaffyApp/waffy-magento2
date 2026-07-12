<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

use Waffy\Ecommerce\Exception\ValidationException;

/**
 * Merchant (seller / provider) identity passed to Waffy when creating a
 * payment. Identifies the recipient of the escrow funds.
 */
readonly class Merchant
{
    public function __construct(
        public string $phone,
        public ?string $waffyUserId = null,
        public ?string $name = null,
    ) {
        if (!preg_match('/^\+\d{8,15}$/', $phone)) {
            throw new ValidationException(
                sprintf('Merchant phone must be E.164, got "%s".', $phone),
            );
        }
    }
}
