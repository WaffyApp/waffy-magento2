<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Dto;

use Waffy\Ecommerce\Exception\ValidationException;

/**
 * Product / service details sent to Waffy when creating a contract.
 *
 * Maps to the `itemDetail` object in the Waffy API plus top-level contract fields.
 *
 * returnPolicy   — NO_RETURN | RETURNABLE (default: NO_RETURN for physical goods sold online)
 * returnFeePayee — PROVIDER | CUSTOMER (who pays return shipping; default: PROVIDER)
 * category       — free-text category, e.g. "Electronics", "Services", "Clothing"
 */
final readonly class ProductInfo
{
    /**
     * @param string[] $images Image URLs (max recommended: 5)
     */
    public function __construct(
        public string $title,
        public string $description,
        public array $images = [],
        public string $category = 'Services',
        public string $returnPolicy = 'NO_RETURN',
        public string $returnFeePayee = 'PROVIDER',
    ) {
        if (trim($title) === '') {
            throw new ValidationException('ProductInfo: title cannot be empty.');
        }
    }
}
