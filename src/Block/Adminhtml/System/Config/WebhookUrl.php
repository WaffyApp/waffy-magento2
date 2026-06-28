<?php

declare(strict_types=1);

namespace Waffy\Payment\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Renders a read-only, click-to-select input showing the store's Waffy webhook URL.
 * Displayed in Admin → Stores → Configuration → Sales → Payment Methods → Waffy Escrow Payment.
 */
class WebhookUrl extends Field
{
    public function __construct(
        Context $context,
        private readonly StoreManagerInterface $storeManager,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        try {
            $baseUrl = rtrim($this->storeManager->getDefaultStoreView()->getBaseUrl(), '/');
        } catch (\Exception) {
            $baseUrl = '';
        }

        $webhookUrl = $baseUrl . '/waffy/webhook';

        return sprintf(
            '<input type="text" readonly value="%s" '
            . 'style="width:100%%;font-family:monospace;background:#f5f5f5;cursor:pointer" '
            . 'onclick="this.select()" title="Click to select all" />',
            htmlspecialchars($webhookUrl, ENT_QUOTES, 'UTF-8'),
        );
    }
}
