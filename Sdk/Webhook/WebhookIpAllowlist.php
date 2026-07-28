<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Webhook;

/**
 * Request-origin check for inbound webhooks.
 *
 * Waffy webhooks are unsigned (confirmed 2026-06-28), so an IP allowlist is
 * the only origin control available. This is platform-neutral: adapters pass
 * the client IP and the configured allowlist; the SDK owns the matching rules
 * (exact IPv4/IPv6, or IPv4 CIDR ranges).
 */
final class WebhookIpAllowlist
{
    /**
     * An empty allowlist means "no restriction" (allow all). Otherwise the IP
     * must match at least one entry.
     *
     * @param list<string> $allowed Exact IPs or IPv4 CIDR ranges (e.g. 203.0.113.0/24)
     */
    public static function isAllowed(string $clientIp, array $allowed): bool
    {
        if ($allowed === []) {
            return true;
        }

        foreach ($allowed as $entry) {
            if (self::matches($clientIp, $entry)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $clientIp, string $entry): bool
    {
        if (!str_contains($entry, '/')) {
            return $clientIp === $entry;
        }

        // IPv4 CIDR match.
        [$subnet, $bits] = explode('/', $entry, 2);
        $ipLong     = ip2long($clientIp);
        $subnetLong = ip2long($subnet);
        $bits       = (int) $bits;

        if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
