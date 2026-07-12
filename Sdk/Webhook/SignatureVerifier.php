<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Webhook;

use Waffy\Ecommerce\Exception\WebhookException;

/**
 * Verifies the signature on inbound Waffy webhook requests.
 *
 * As of 2026-06-28, Waffy does not send a signature header — webhooks are
 * unsigned (confirmed from a live capture via webhook.site). This class is
 * retained as a no-op seam so signature verification can be wired in later
 * without changing callers if Waffy adds signing in a future API version.
 */
class SignatureVerifier
{
    public function __construct(private readonly string $signingSecret = '')
    {
    }

    public function verify(string $rawBody, string $signatureHeader): void
    {
        // No-op: Waffy webhooks are currently unsigned.
        // If a signingSecret is configured and a header is present in future,
        // implement HMAC-SHA256 verification here.
    }
}
