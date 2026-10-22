<?php

declare(strict_types=1);

namespace Waffy\Payment\Controller\Adminhtml\Setup;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Waffy\Ecommerce\Config\Environment;
use Waffy\Payment\Model\Setup\SetupService;

/** POST (AJAX): preview an uploaded env file without saving it. */
class EnvPreview extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Waffy_Payment::setup';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly SetupService $setup,
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $environment = Environment::fromString((string) $this->getRequest()->getParam('environment'));

        return $this->jsonFactory->create()->setData(
            $this->setup->preview($environment, (string) $this->getRequest()->getParam('envJson')),
        );
    }
}
