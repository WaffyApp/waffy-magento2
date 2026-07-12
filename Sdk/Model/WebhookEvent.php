<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Model;

use Waffy\Ecommerce\Exception\WebhookException;

/**
 * Parsed inbound webhook event from Waffy.
 *
 * The raw payload is decoded into structured fields plus a `data` array of
 * event-specific fields. Handlers registered with WebhookRouter receive an
 * instance of this class.
 *
 * `eventType` will be one of WebhookEventType — unknown event types resolve
 * to null so handlers can choose to ignore or log them rather than crashing.
 */
readonly class WebhookEvent
{
    /**
     * @param array<string, mixed> $data Event-specific payload (e.g. `data.object` from Stripe-style envelope)
     */
    public function __construct(
        public string $id,
        public string $rawType,
        public ?WebhookEventType $eventType,
        public array $data,
        public int $createdAt,
    ) {
    }

    /**
     * Parse a decoded JSON payload into a WebhookEvent. Throws on missing
     * required fields — the platform adapter should respond HTTP 400.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $id = $payload['id'] ?? null;
        $type = $payload['type'] ?? null;
        $createdAt = $payload['created'] ?? null;
        $dataWrap = $payload['data'] ?? [];

        if (!is_string($id) || $id === '') {
            throw new WebhookException('Webhook payload missing "id".');
        }
        if (!is_string($type) || $type === '') {
            throw new WebhookException('Webhook payload missing "type".');
        }
        if (!is_int($createdAt) && !is_string($createdAt)) {
            throw new WebhookException('Webhook payload missing "created" timestamp.');
        }
        if (!is_array($dataWrap)) {
            throw new WebhookException('Webhook payload "data" must be an object.');
        }

        $data = isset($dataWrap['object']) && is_array($dataWrap['object'])
            ? $dataWrap['object']
            : $dataWrap;

        return new self(
            id: $id,
            rawType: $type,
            eventType: WebhookEventType::tryFrom($type),
            data: $data,
            createdAt: is_int($createdAt) ? $createdAt : (int) $createdAt,
        );
    }
}
