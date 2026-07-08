<?php

declare(strict_types=1);

namespace Waffy\Payment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Environment implements OptionSourceInterface
{
    public const SANDBOX    = 'sandbox';
    public const PRODUCTION = 'production';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::SANDBOX,    'label' => __('Sandbox (Dev)')],
            ['value' => self::PRODUCTION, 'label' => __('Production')],
        ];
    }
}
