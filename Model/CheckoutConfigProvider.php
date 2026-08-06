<?php

declare(strict_types=1);

namespace Waffy\Payment\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\UrlInterface;

/**
 * Publishes what the checkout JS needs into window.checkoutConfig.payment.waffy_payment.
 *
 * `isSandbox` gates the per-call checklist in the disclaimer modal: on a sandbox
 * store it lists every Waffy API call as it happens, which is what makes the
 * token cache visible; in production only the progress bar is shown, because the
 * step names describe our integration's plumbing rather than anything a buyer
 * needs to read.
 */
class CheckoutConfigProvider implements ConfigProviderInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly UrlInterface $url,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return [
            'payment' => [
                'waffy_payment' => [
                    'isSandbox'   => $this->config->isSandbox(),
                    'progressUrl' => $this->url->getUrl('waffy/checkout/progress'),
                ],
            ],
        ];
    }
}
