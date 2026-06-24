<?php

declare(strict_types=1);

namespace Waffy\Payment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ReturnFeePayee implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'PROVIDER', 'label' => __('Merchant (Provider)')],
            ['value' => 'CUSTOMER', 'label' => __('Customer')],
        ];
    }
}
