<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Mapper;

use Waffy\Ecommerce\Model\PlatformOrder;

/**
 * Converts a platform-agnostic PlatformOrder into the request payload for
 * POST /payment-actions/v2/payments/ (InitiatePaymentRequest DTO).
 *
 * Confirmed required fields (from live 400 validation response 2026-05-17):
 *   fromUserId      — buyer's Waffy user ID (falls back to phone if waffyUserId not set)
 *   fromUserName    — buyer's display name (falls back to phone)
 *   beneficiaryId   — merchant's Waffy user ID
 *   beneficiaryName — merchant's display name
 *   domainId        — merchant's Waffy domain ID (TBD: confirm exact source with backend dev)
 *
 * Also sent (accepted, no validation error):
 *   amount, currency, platform, metadata
 */
final class OrderContractMapper
{
    public function __construct(
        private readonly string $platformId,
        private readonly string $domainId = '',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toCreatePaymentRequest(PlatformOrder $order): array
    {
        $payload = [
            'amount'          => $order->total->minorUnits,
            'currency'        => $order->total->currency,
            'fromUserId'      => $order->buyer->waffyUserId ?? $order->buyer->phone,
            'fromUserName'    => $order->buyer->name ?? $order->buyer->phone,
            'beneficiaryId'   => $order->merchant->waffyUserId,
            'beneficiaryName' => $order->merchant->name,
            'domainId'        => $this->domainId,
            'platform'        => $this->platformId,
            'metadata'        => array_merge(
                ['platform_order_id' => $order->platformOrderId],
                $order->metadata,
            ),
        ];

        if ($order->buyer->email !== null) {
            $payload['customerEmail'] = $order->buyer->email;
        }

        if ($order->items !== []) {
            $payload['metadata']['items'] = array_map(
                static fn ($item): array => [
                    'sku'        => $item->sku,
                    'name'       => $item->name,
                    'quantity'   => $item->quantity,
                    'unit_price' => $item->unitPrice->minorUnits,
                ],
                $order->items,
            );
        }

        return $payload;
    }
}
