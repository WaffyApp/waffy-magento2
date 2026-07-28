<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

use Waffy\Ecommerce\Exception\WebhookException;

/**
 * Parsed inbound webhook event from Waffy.
 *
 * Real payload shape (confirmed via live captures 2026-06-28):
 *   { "contractId": "<milestoneId>", "status": "PAID|CREATED|...", "referenceId": "..." }
 *
 * Note: the `contractId` field is actually the milestone ID the platform
 * stored when checkout started (e.g. Magento's ext_order_id) — it is the key
 * the adapter uses to locate the local order.
 *
 * Unknown status strings resolve to a null `status` so adapters can log and
 * ignore them rather than crashing. The full decoded payload is kept in `raw`.
 */
readonly class WebhookEvent
{
    /**
     * @param array<string, mixed> $raw The full decoded payload
     */
    public function __construct(
        public string $contractId,
        public ?WebhookStatus $status,
        public string $rawStatus,
        public string $referenceId,
        public array $raw,
    ) {
    }

    /**
     * Parse a decoded JSON payload into a WebhookEvent. Throws when the
     * required `contractId` is missing — the platform adapter should respond
     * HTTP 400/422 in that case.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $contractId = (string) ($payload['contractId'] ?? '');
        if ($contractId === '') {
            throw new WebhookException('Webhook payload missing "contractId".');
        }

        $rawStatus = strtoupper((string) ($payload['status'] ?? ''));

        return new self(
            contractId:  $contractId,
            status:      WebhookStatus::tryFrom($rawStatus),
            rawStatus:   $rawStatus,
            referenceId: (string) ($payload['referenceId'] ?? ''),
            raw:         $payload,
        );
    }
}
