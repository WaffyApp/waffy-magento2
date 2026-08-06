<?php

declare(strict_types=1);

namespace Waffy\Payment\Controller\Checkout;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Waffy\Ecommerce\Progress\CheckoutStep;
use Waffy\Ecommerce\Progress\StepStatus;
use Waffy\Payment\Model\CheckoutProgress;

/**
 * GET /waffy/checkout/progress
 *
 * Live state of the checkout the caller is currently running, polled by the
 * disclaimer modal while Controller\Checkout\Start does the actual work.
 *
 * Deliberately session-free. Magento holds the PHP session lock for the whole of
 * a request, so Start — which can take many seconds — blocks anything else that
 * touches the session. A poll that waited on that lock would return only after
 * checkout had finished, which defeats the point. So this action reads the
 * progress key straight from the request cookies and looks the state up in the
 * cache; it never asks for a session, a cart or a customer.
 *
 * The response carries no secrets: step ids, statuses and durations only.
 */
class Progress implements HttpGetActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly CheckoutProgress $progress,
    ) {}

    public function execute(): ResultInterface
    {
        $state    = $this->progress->read($this->keyFromCookie());
        $recorded = is_array($state['steps'] ?? null) ? $state['steps'] : [];

        $steps     = [];
        $succeeded = 0;

        foreach (CheckoutStep::sequence() as $step) {
            $status = isset($recorded[$step->value]['status'])
                ? (string) $recorded[$step->value]['status']
                : 'pending';

            // Only calls that got us further count toward the bar. A failed step
            // is settled but is not progress — a full bar beside a red cross
            // would read as success.
            if ($status === StepStatus::Done->value || $status === StepStatus::Cached->value) {
                $succeeded++;
            }

            $steps[] = [
                'id'     => $step->value,
                'label'  => (string) __($step->label()),
                'status' => $status,
                'ms'     => isset($recorded[$step->value]['ms']) ? (float) $recorded[$step->value]['ms'] : null,
            ];
        }

        $total = max(1, count($steps));

        return $this->jsonFactory->create()->setData([
            // 'idle' until something has been published — the modal keeps polling
            // rather than assuming a finished (or broken) checkout.
            'state'   => is_array($state) ? (string) ($state['state'] ?? 'running') : 'idle',
            'percent' => (int) round($succeeded / $total * 100),
            'steps'   => $steps,
        ]);
    }

    /**
     * Read the cookie directly off the request rather than through
     * CookieManagerInterface, which pulls in session-aware machinery this action
     * is specifically avoiding.
     */
    private function keyFromCookie(): string
    {
        $raw = $this->request->getCookie(CheckoutProgress::COOKIE, '');

        return CheckoutProgress::sanitizeKey(is_string($raw) ? $raw : '');
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /** Read-only GET scoped to the caller's own cookie — nothing to forge. */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
