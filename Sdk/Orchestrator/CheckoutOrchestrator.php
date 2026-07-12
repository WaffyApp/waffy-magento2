<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Orchestrator;

use Waffy\Ecommerce\Client\WaffyClient;
use Waffy\Ecommerce\Exception\ApiException;
use Waffy\Ecommerce\Idempotency\KeyGenerator;
use Waffy\Ecommerce\Mapper\OrderContractMapper;
use Waffy\Ecommerce\Model\Money;
use Waffy\Ecommerce\Model\PaymentSession;
use Waffy\Ecommerce\Model\PaymentStatus;
use Waffy\Ecommerce\Model\PlatformOrder;

/**
 * High-level checkout flow: takes a platform order, creates a Waffy escrow
 * payment, returns the redirect URL + contract id.
 *
 * Idempotency: each create-payment call is scoped to
 * "{platformId}:create-payment:{platformOrderId}" so duplicate clicks /
 * webhook retries don't create duplicate payments.
 */
class CheckoutOrchestrator
{
    /**
     * Path on the Waffy backend that creates a payment for an external
     * order. Default targets the v2 payments endpoint per the React Native
     * SDK + Postman collection; can be overridden if the backend exposes a
     * different path for e-commerce flows.
     */
    private const DEFAULT_CREATE_PAYMENT_PATH = '/payment-actions/v2/payments/';

    public function __construct(
        private readonly WaffyClient $client,
        private readonly OrderContractMapper $mapper,
        private readonly KeyGenerator $idempotency,
        private readonly string $createPaymentPath = self::DEFAULT_CREATE_PAYMENT_PATH,
    ) {
    }

    public function createPayment(PlatformOrder $order): PaymentSession
    {
        $payload = $this->mapper->toCreatePaymentRequest($order);

        $idempotencyKey = $this->idempotency->scoped(
            principalId: 'sdk',
            operation: 'create-payment',
            scopeKey: $order->platformOrderId,
        );

        $response = $this->client->post($this->createPaymentPath, $payload, $idempotencyKey);

        return $this->parsePaymentSession($response->data, $order->total);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function parsePaymentSession(array $body, Money $fallbackAmount): PaymentSession
    {
        // The Waffy backend wraps successful responses in {success, data, meta}
        // per the developer-portal docs. Be tolerant: accept either the wrapped
        // shape or a flat object.
        $payment = isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;

        $contractId = $this->stringField($payment, ['contract_id', 'contractId', 'payment_id', 'paymentId', 'id']);
        $paymentUrl = $this->stringField($payment, ['payment_url', 'paymentUrl', 'redirect_url', 'redirectUrl']);

        if ($contractId === null) {
            throw new ApiException(
                'Waffy response missing contract/payment id.',
                statusCode: 0,
                responseBody: $body,
            );
        }
        if ($paymentUrl === null) {
            throw new ApiException(
                'Waffy response missing paymentUrl.',
                statusCode: 0,
                responseBody: $body,
            );
        }

        $statusRaw = $this->stringField($payment, ['status', 'payment_status']);
        $status = $statusRaw !== null ? (PaymentStatus::tryFrom(strtolower($statusRaw)) ?? PaymentStatus::PENDING) : PaymentStatus::PENDING;

        $shortId = $this->stringField($payment, ['short_id', 'shortId']);

        return new PaymentSession(
            contractId: $contractId,
            paymentUrl: $paymentUrl,
            amount: $fallbackAmount,
            status: $status,
            shortId: $shortId,
        );
    }

    /**
     * @param array<string, mixed> $payment
     * @param list<string>         $keys
     */
    private function stringField(array $payment, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($payment[$key]) && is_string($payment[$key]) && $payment[$key] !== '') {
                return $payment[$key];
            }
        }

        return null;
    }
}
