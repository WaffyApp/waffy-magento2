<?php

declare(strict_types=1);

namespace Waffy\Payment\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Waffy\Ecommerce\Config\Environment;
use Waffy\Payment\Controller\Adminhtml\Setup\Verify;
use Waffy\Payment\Model\Setup\Connection;
use Waffy\Payment\Model\Setup\ConnectionRepository;
use Waffy\Payment\Model\Setup\SetupService;

/**
 * The setup wizard page (template Waffy_Payment::setup.phtml): which step to
 * show, for which environment, and the connection being set up.
 *
 *   step 1 — choose Sandbox or Live, create the client in the Waffy portal
 *   step 2 — upload its env file, or type the values (saved as a draft)
 *   step 3 — run the live checks; when they pass, the draft goes live
 */
class Setup extends Template
{
    protected $_template = 'Waffy_Payment::setup.phtml';

    /** @var list<array{label: string, state: string, detail?: string}>|null */
    private ?array $checks = null;

    public function __construct(
        Context $context,
        private readonly ConnectionRepository $connections,
        private readonly SetupService $setup,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getActive(): ?Connection
    {
        return $this->connections->getActive();
    }

    public function getDraft(): ?Connection
    {
        return $this->connections->getDraft();
    }

    /** What step 3 verifies: the draft when there is one, else the live connection. */
    public function getCandidate(): ?Connection
    {
        return $this->getDraft() ?? $this->getActive();
    }

    public function isLive(): bool
    {
        return $this->connections->isActiveEnabled() && $this->getActive() !== null;
    }

    public function getStep(): int
    {
        $step = (int) $this->getRequest()->getParam('step');

        return in_array($step, [1, 2, 3], true) ? $step : ($this->getCandidate() ? 3 : 1);
    }

    public function getEnvironment(): Environment
    {
        $requested = (string) $this->getRequest()->getParam('env');
        if ($requested === Environment::Sandbox->value || $requested === Environment::Production->value) {
            return Environment::fromString($requested);
        }

        return $this->getCandidate()?->environment ?? Environment::Sandbox;
    }

    /** The connection saved for the shown environment, to prefill step 2. */
    public function getSavedFor(Environment $environment): ?Connection
    {
        $saved = $this->getCandidate();

        return $saved !== null && $saved->environment === $environment ? $saved : null;
    }

    /** The checklist of the last "Run checks" (shown once). */
    public function getChecks(): ?array
    {
        if ($this->checks === null) {
            $session = $this->_backendSession;
            $checks = $session->getData(Verify::SESSION_CHECKS, true);
            $this->checks = is_array($checks) ? $checks : [];
        }

        return $this->checks === [] ? null : $this->checks;
    }

    public function getWebhookUrl(): string
    {
        return $this->setup->webhookUrl();
    }

    public function stepUrl(int $step, ?Environment $environment = null): string
    {
        return $this->getUrl('waffy/setup/index', ['step' => $step, 'env' => ($environment ?? $this->getEnvironment())->value]);
    }

    public function envLabel(Environment $environment): string
    {
        return $environment === Environment::Sandbox ? (string) __('Sandbox') : (string) __('Live');
    }
}
