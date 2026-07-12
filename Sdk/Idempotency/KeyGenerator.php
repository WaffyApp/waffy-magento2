<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Idempotency;

use Ramsey\Uuid\Uuid;

/**
 * Generates idempotency keys for non-idempotent Waffy API operations.
 *
 * Two modes:
 *   - random()  — UUIDv4. Use when there is no natural deduplication key.
 *   - scoped()  — deterministic hash of (principalId, operation, scopeKey).
 *                 Use when the same logical operation (e.g. "create payment
 *                 for order #1234") MUST resolve to the same idempotency key
 *                 across retries from different processes — for example,
 *                 when a Magento order create event is observed twice.
 *
 * The Waffy backend deduplicates by idempotency key inside its retention
 * window; we never send a previously-used key for a different payload.
 */
class KeyGenerator
{
    public function random(): string
    {
        return Uuid::uuid4()->toString();
    }

    /**
     * Deterministic key for an operation. Same inputs → same key.
     *
     * Format: "{operation}:{12-char-hash}" — readable in logs without leaking
     * the underlying scope key.
     */
    public function scoped(string $principalId, string $operation, string $scopeKey): string
    {
        $material = $principalId . '|' . $operation . '|' . $scopeKey;
        $hash = substr(hash('sha256', $material), 0, 32);

        return $operation . ':' . $hash;
    }
}
