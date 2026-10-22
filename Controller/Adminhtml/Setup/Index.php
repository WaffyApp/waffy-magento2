<?php

declare(strict_types=1);

namespace Waffy\Payment\Controller\Adminhtml\Setup;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Stores → Waffy setup: the three-step wizard (create the client, connect,
 * verify & activate). Any step can be reopened later via ?step=1..3.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Waffy_Payment::setup';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Waffy_Payment::setup');
        $page->getConfig()->getTitle()->prepend((string) __('Waffy setup'));

        return $page;
    }
}
