<?php

declare(strict_types=1);

namespace Waffy\Payment\Model;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Waffy\Ecommerce\Dto\CheckoutRequest;
use Waffy\Ecommerce\Dto\CheckoutResult;
use Waffy\Ecommerce\Exception\NotImplementedException;
use Waffy\Ecommerce\Exception\WaffyException;
use Waffy\Ecommerce\Orchestrator\EcomCheckoutOrchestrator;

/**
 * The Waffy ID checkout as separate steps, so the checkout page can stop for an
 * OTP between them — the same steps, guards and order as the Shopify app:
 *
 *   prepare ─┬─▶ startPayment ─────────────┐
 *   begin ───┴─(consent resolved)─▶ ticket ─┴─▶ go → Waffy
 *
 * `begin` links the buyer and, only when Waffy says their consent is
 * required, sends an OTP; `verify` checks the code. startPayment never waits
 * for the buyer; the ticket waits for the contract and the buyer's consent.
 *
 * Every method returns an array for the JSON response: ['ok' => true, …] or
 * ['ok' => false, 'status' => HTTP status, 'error' => code, 'message' => text].
 */
class CheckoutSteps
{
    /**
     * Digits in the OTP Waffy sends. TBD(waffy): confirm the length — #22 in
     * project-docs/04-open-questions.md. The checkout modal uses the same value.
     */
    public const OTP_LENGTH = 4;

    public function __construct(
        private readonly Config $config,
        private readonly OrchestratorFactory $orchestratorFactory,
        private readonly CheckoutRequestBuilder $requestBuilder,
        private readonly CheckoutState $state,
        private readonly CheckoutProgress $progress,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /** Contract → milestone → parties. Reuses the contract an earlier attempt made. */
    public function prepare(Order $order): array
    {
        return $this->step($order, 'prepare', function (array $row, EcomCheckoutOrchestrator $orchestrator, CheckoutRequest $request) use ($order): array {
            $orderId = (int) $order->getId();
            if ($this->state->prepared($row) !== null) {
                if ((int) ($row['cleanup_pending'] ?? 0) === 1) {
                    $this->state->update($orderId, ['cleanup_pending' => 0]);
                }

                return ['ok' => true];
            }

            $contract = $orchestrator->prepareContract($request);
            $this->state->update($orderId, [
                'contract_id'     => $contract->contractId,
                'milestone_id'    => $contract->milestoneId,
                'short_id'        => $contract->shortId,
                'cleanup_pending' => 0,
            ]);

            // The webhook finds the order by milestone id, so store it now.
            $order->setState(Order::STATE_PENDING_PAYMENT)->setStatus('pending_payment');
            $order->setExtOrderId($contract->milestoneId);
            $this->orderRepository->save($order);

            return ['ok' => true];
        });
    }

    /**
     * Link Customer and — only when Waffy says consent is required — send an
     * OTP. $resend skips straight to sending a new code.
     */
    public function begin(Order $order, bool $resend = false): array
    {
        return $this->step($order, 'begin', function (array $row, EcomCheckoutOrchestrator $orchestrator, CheckoutRequest $request) use ($order, $resend): array {
            $orderId = (int) $order->getId();
            $consent = (string) ($row['consent_state'] ?? CheckoutState::CONSENT_UNKNOWN);
            if ($consent === CheckoutState::CONSENT_VERIFIED || $consent === CheckoutState::CONSENT_NOT_REQUIRED) {
                return ['ok' => true, 'consentRequired' => false, 'otpSent' => false];
            }

            if (!$resend) {
                $registration = $orchestrator->partner->prepareCustomer($request->clientId, $request->clientSecret, $request->customer);
                if (!$registration->consentRequired()) {
                    $this->state->update($orderId, ['consent_state' => CheckoutState::CONSENT_NOT_REQUIRED]);

                    return ['ok' => true, 'consentRequired' => false, 'otpSent' => false];
                }
            }

            $sent = $orchestrator->partner->sendOtp($request->clientId, $request->clientSecret, $request->customer->phoneNumber);
            $this->state->update($orderId, [
                'consent_state' => $sent->sent ? CheckoutState::CONSENT_OTP_SENT : CheckoutState::CONSENT_UNKNOWN,
            ]);

            return ['ok' => true, 'consentRequired' => true, 'otpSent' => $sent->sent, 'reason' => $sent->reason];
        });
    }

    /** Check the code the buyer typed. Success is what lets the ticket through. */
    public function verify(Order $order, string $otp): array
    {
        $code = (string) preg_replace('/\s+/', '', $otp);
        if (preg_match('/^\d{' . self::OTP_LENGTH . '}$/', $code) !== 1) {
            return ['ok' => false, 'status' => 422, 'error' => 'otp_format'];
        }

        return $this->step($order, 'verify', function (array $row, EcomCheckoutOrchestrator $orchestrator, CheckoutRequest $request) use ($order, $code): array {
            if (($row['consent_state'] ?? '') !== CheckoutState::CONSENT_OTP_SENT) {
                return ['ok' => false, 'status' => 409, 'error' => 'otp_not_sent'];
            }
            $orchestrator->partner->verifyOtp($request->clientId, $request->clientSecret, $request->customer->phoneNumber, $code);
            $this->state->update((int) $order->getId(), ['consent_state' => CheckoutState::CONSENT_VERIFIED]);

            return ['ok' => true];
        }, 'otp_invalid');
    }

    /** The payment page URL. Waits for prepare only — not for the buyer's consent. */
    public function startPayment(Order $order): array
    {
        return $this->step($order, 'start-payment', function (array $row, EcomCheckoutOrchestrator $orchestrator, CheckoutRequest $request) use ($order): array {
            $contract = $this->state->prepared($row);
            if ($contract === null) {
                return ['ok' => false, 'status' => 409, 'error' => 'not_prepared'];
            }
            $this->state->update((int) $order->getId(), ['payment_url' => $orchestrator->startPayment($request, $contract)]);

            return ['ok' => true];
        });
    }

    /** The buyer's payment ticket. Waits for prepare AND the buyer's consent. */
    public function ticket(Order $order): array
    {
        return $this->step($order, 'ticket', function (array $row, EcomCheckoutOrchestrator $orchestrator, CheckoutRequest $request) use ($order): array {
            $contract = $this->state->prepared($row);
            if ($contract === null) {
                return ['ok' => false, 'status' => 409, 'error' => 'not_prepared'];
            }
            $consent = (string) ($row['consent_state'] ?? '');
            if ($consent !== CheckoutState::CONSENT_VERIFIED && $consent !== CheckoutState::CONSENT_NOT_REQUIRED) {
                return ['ok' => false, 'status' => 409, 'error' => 'consent_pending'];
            }
            $paymentUrl = (string) ($row['payment_url'] ?? '');
            $ticket = $orchestrator->paymentTicket($request, $contract, $paymentUrl !== '' ? $paymentUrl : null);
            $this->state->update((int) $order->getId(), ['ticket' => $ticket]);

            return ['ok' => true];
        });
    }

    /** Once start-payment and the ticket are both done: the Waffy URL to send the buyer to. */
    public function go(Order $order): array
    {
        if (!$this->isOpen($order)) {
            return ['ok' => false, 'status' => 409, 'error' => 'not_open'];
        }
        $orderId    = (int) $order->getId();
        $row        = $this->state->get($orderId);
        $contract   = $this->state->prepared($row);
        $paymentUrl = (string) ($row['payment_url'] ?? '');
        $ticket     = $this->state->ticket($row);
        if ($contract === null || $paymentUrl === '' || $ticket === null) {
            return ['ok' => false, 'status' => 409, 'error' => 'not_ready'];
        }

        $url = CheckoutResult::withTicket($contract, $paymentUrl, $ticket)->paymentUrlWithBuyer();
        // The ticket is single-use: clear it so a retry mints a fresh one.
        $this->state->update($orderId, ['ticket' => null]);
        $this->logger->info('Waffy checkout started', ['order' => $order->getIncrementId(), 'milestoneId' => $contract->milestoneId]);

        return ['ok' => true, 'url' => $url];
    }

    /**
     * The buyer gave up before paying (cancelled the OTP): remove the contract
     * made for them and cancel the order. Best-effort on the Waffy side.
     */
    public function cancel(Order $order): void
    {
        if (!$this->isOpen($order)) {
            return;
        }
        $this->discard($order);
        if ($order->canCancel()) {
            $order->cancel();
            $order->addCommentToStatusHistory((string) __('The buyer cancelled the Waffy payment before paying.'), false, false);
            $this->orderRepository->save($order);
        }
    }

    /**
     * Best-effort removal of an order's unpaid contract. Never throws: a failure
     * — including Waffy not offering the endpoint yet — leaves the order flagged
     * for the cron sweep. Never touches a paid order.
     */
    public function discard(Order $order): bool
    {
        if (in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE, Order::STATE_CLOSED], true)) {
            return false;
        }
        $orderId = (int) $order->getId();
        $contract = $this->state->prepared($this->state->get($orderId));
        if ($contract === null) {
            return false;
        }

        $storeId = (int) $order->getStoreId();
        try {
            $this->orchestratorFactory->create($storeId)->discardContract(
                $this->config->getClientId($storeId),
                $this->config->getClientSecret($storeId),
                $this->config->getClientAdminEmail($storeId),
                $this->config->getClientAdminPassword($storeId),
                $contract->contractId,
            );
            $this->state->update($orderId, [
                'contract_id'     => null,
                'milestone_id'    => null,
                'short_id'        => null,
                'payment_url'     => null,
                'ticket'          => null,
                'cleanup_pending' => 0,
            ]);

            return true;
        } catch (\Throwable $e) {
            $context = ['order' => $order->getIncrementId(), 'contractId' => $contract->contractId, 'error' => $e->getMessage()];
            if ($e instanceof NotImplementedException) {
                $this->logger->warning('Waffy contract cleanup deferred', $context);
            } else {
                $this->logger->error('Waffy contract cleanup failed', $context);
            }
            $this->state->update($orderId, ['cleanup_pending' => 1]);

            return false;
        }
    }

    /**
     * Run one checkout step for an order still waiting for Waffy, turning a Waffy
     * failure into the message the buyer sees (Waffy's own text when it gave one).
     *
     * @param callable(array, EcomCheckoutOrchestrator, CheckoutRequest): array $run
     */
    private function step(Order $order, string $name, callable $run, string $failureCode = 'waffy_error'): array
    {
        if (!$this->isOpen($order)) {
            return ['ok' => false, 'status' => 409, 'error' => 'not_open'];
        }
        $storeId = (int) $order->getStoreId();
        if (!$this->config->hasCompleteCredentials($storeId)) {
            return ['ok' => false, 'status' => 409, 'error' => 'not_configured'];
        }

        try {
            $request = $this->requestBuilder->build($order);
            $orchestrator = $this->orchestratorFactory->create(
                $storeId,
                $this->progress->start('', 'checkout ' . $name . ' #' . $order->getIncrementId()),
            );

            return $run($this->state->get((int) $order->getId()), $orchestrator, $request);
        } catch (WaffyException $e) {
            $message = $e->getUserMessage() ?? $e->getMessage();
            $this->logger->error('Waffy checkout ' . $name . ' failed for order #' . $order->getIncrementId(), [
                'exception'    => $e->getMessage(),
                'responseBody' => $e->getResponseBody(),
            ]);

            return ['ok' => false, 'status' => 422, 'error' => $failureCode, 'message' => $message];
        }
    }

    /** A Waffy order not yet paid, cancelled or closed. */
    private function isOpen(Order $order): bool
    {
        return $order->getPayment()?->getMethod() === Payment::CODE
            && in_array($order->getState(), [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT], true);
    }
}
