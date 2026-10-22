<?php

declare(strict_types=1);

namespace Waffy\Payment\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Waffy\Payment\Model\Setup\ConnectionRepository;

/**
 * Shows which Waffy connection is active and links to the setup wizard, which
 * is where the connection is created and edited (Stores → Waffy setup).
 */
class SetupStatus extends Field
{
    public function __construct(
        Context $context,
        private readonly ConnectionRepository $connections,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $active = $this->connections->getActive();
        $status = $active === null
            ? (string) __('Not set up yet.')
            : (string) __(
                '%1 — client %2, organization account %3.',
                $active->environment->value === 'sandbox' ? __('Sandbox') : __('Live'),
                $active->clientId,
                $active->orgAccountId,
            );

        return sprintf(
            '<p style="margin:.4rem 0">%s</p><a class="action-default" href="%s">%s</a>',
            $this->escapeHtml($status),
            $this->escapeUrl($this->getUrl('waffy/setup/index')),
            $this->escapeHtml($active === null ? __('Set up Waffy') : __('Open the Waffy setup')),
        );
    }
}
