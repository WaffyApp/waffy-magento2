<?php

declare(strict_types=1);

namespace Waffy\Payment\Controller\Adminhtml\Setup;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Waffy\Ecommerce\Config\Environment;
use Waffy\Ecommerce\Exception\ValidationException;
use Waffy\Payment\Model\Setup\ConnectionRepository;
use Waffy\Payment\Model\Setup\SetupService;

/**
 * Step 2: validate the uploaded env file or the typed values and save them as
 * a draft. The live connection is untouched until step 3 verifies the draft.
 */
class Connect extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Waffy_Payment::setup';

    private const FIELDS = [
        'mode', 'envJson', 'clientId', 'adminUsername', 'orgAccountId',
        'idBaseUrl', 'authBaseUrl', 'apiBaseUrl', 'clientSecret', 'adminPassword',
    ];

    public function __construct(
        Context $context,
        private readonly SetupService $setup,
        private readonly ConnectionRepository $connections,
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $environment = Environment::fromString((string) $this->getRequest()->getParam('environment'));
        $input = [];
        foreach (self::FIELDS as $field) {
            $input[$field] = (string) $this->getRequest()->getParam($field, '');
        }

        try {
            $connection = $this->setup->connect(
                $environment,
                $input,
                $this->connections->getDraft() ?? $this->connections->getActive(),
            );
        } catch (ValidationException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return $this->resultRedirectFactory->create()
                ->setPath('waffy/setup/index', ['step' => 2, 'env' => $environment->value]);
        }

        $this->connections->saveDraft($connection);
        $this->messageManager->addSuccessMessage((string) __('Saved. Run the checks to activate it.'));

        return $this->resultRedirectFactory->create()->setPath('waffy/setup/index', ['step' => 3]);
    }
}
