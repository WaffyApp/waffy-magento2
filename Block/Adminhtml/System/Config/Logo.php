<?php

declare(strict_types=1);

namespace Waffy\Payment\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Waffy\Ecommerce\Branding\Logo as WaffyLogo;

/**
 * Renders the Waffy wordmark at the top of the payment method's configuration
 * section (Admin → Stores → Configuration → Sales → Payment Methods → Waffy
 * Escrow Payment), so the merchant can see at a glance whose settings these are.
 *
 * The artwork comes from the SDK ({@see WaffyLogo}) and is inlined rather than
 * served from view/adminhtml/web — no static-content deploy needed for it to
 * show up, and the WooCommerce plugin renders the exact same markup.
 */
class Logo extends Field
{
    /**
     * Full-width row: the logo is a banner, not a labelled field, so it skips
     * the label/value/scope cells the default field row would draw.
     */
    public function render(AbstractElement $element): string
    {
        return sprintf(
            '<tr id="row_%s"><td colspan="5" style="padding:1.5rem 0 0.5rem">%s</td></tr>',
            $element->getHtmlId(),
            WaffyLogo::svg(40),
        );
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return WaffyLogo::svg(40);
    }
}
