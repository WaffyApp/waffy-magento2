<?php

declare(strict_types=1);

namespace Waffy\Payment\Controller\Adminhtml\Setup;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;
use Waffy\Payment\Model\Setup\ConnectionRepository;
use Waffy\Payment\Model\Setup\SetupService;
use Waffy\Payment\Model\TokenWarmer;

/**
 * Step 3: prove the draft works against Waffy, register the webhook, then make
 * it the live connection and enable the payment method. With no draft, the
 * live connection is re-checked.
 */
class Verify extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Waffy_Payment::setup';

    /** Backend-session key the step-3 page reads its checklist from. */
    public const SESSION_CHECKS = 'waffy_setup_checks';

    public function __construct(
        Context $context,
        private readonly SetupService $setup,
        private readonly ConnectionRepository $connections,
        private readonly TokenWarmer $tokenWarmer,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect  = $this->resultRedirectFactory->create()->setPath('waffy/setup/index', ['step' => 3]);
        $candidate = $this->connections->getDraft() ?? $this->connections->getActive();
        if ($candidate === null) {
            $this->messageManager->addErrorMessage((string) __('Connect your Waffy client first.'));

            return $redirect;
        }

        $checks = $this->setup->check($candidate);
        $this->_session->setData(self::SESSION_CHECKS, $checks);

        $failed = array_filter($checks, static fn(array $check): bool => $check['state'] === 'fail');
        if ($failed !== []) {
            $this->logger->warning('Waffy setup verification failed', ['failed' => array_column($failed, 'label')]);
            $this->messageManager->addErrorMessage(
                (string) __('Some checks failed — fix them in step 2, then run the checks again.'),
            );

            return $redirect;
        }

        $this->connections->activate($candidate);
        $this->connections->deleteDraft();
        // New credentials: drop tokens minted under the old ones, then warm.
        $this->tokenWarmer->flushCacheIfCredentialsChanged();
        $this->tokenWarmer->refreshMerchantTokens();

        $this->messageManager->addSuccessMessage((string) __('Waffy is active for this store.'));

        return $redirect;
    }
}
