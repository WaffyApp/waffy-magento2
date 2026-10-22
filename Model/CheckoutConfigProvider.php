<?php

declare(strict_types=1);

namespace Waffy\Payment\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\UrlInterface;
use Waffy\Ecommerce\Branding\Logo;

/**
 * Publishes what the checkout JS needs into window.checkoutConfig.payment.waffy_payment:
 * the step endpoints the modal calls after the order is placed, the OTP length,
 * the cart URL to return to on cancel, and the logo.
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
                    'stepUrl'     => $this->url->getUrl('waffy/checkout'),
                    'cartUrl'     => $this->url->getUrl('checkout/cart'),
                    'otpLength'   => CheckoutSteps::OTP_LENGTH,
                    // Inline SVG rather than a static URL: the payment method
                    // template is a Knockout template with no way to resolve a
                    // view file, and this keeps the logo out of the static
                    // deploy. Same markup the WooCommerce plugin renders.
                    'logoSvg'     => Logo::svg(22),
                ],
            ],
        ];
    }
}
