<?php

declare(strict_types=1);

namespace Waffy\Payment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ReturnPolicy implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'NO_RETURN', 'label' => __('No Return')],
            ['value' => 'RETURNABLE', 'label' => __('Returnable')],
        ];
    }
}
