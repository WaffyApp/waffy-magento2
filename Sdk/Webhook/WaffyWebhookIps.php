<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Webhook;

use Waffy\Ecommerce\Config\Environment;

/**
 * The addresses Waffy sends webhooks from, per environment — the allowlist
 * every platform's webhook endpoint enforces, with no merchant input.
 *
 * TBD(waffy): the egress IPs are not known yet (#11 in
 * project-docs/04-open-questions.md). Until they are filled in here the lists
 * are empty, which WebhookIpAllowlist::isAllowed() treats as "allow all" —
 * the same behaviour as before the allowlist was automatic.
 */
class WaffyWebhookIps
{
    /** @return list<string> Exact IPs or IPv4 CIDR ranges. */
    public static function forEnvironment(Environment $environment): array
    {
        return match ($environment) {
            Environment::Sandbox    => [],
            Environment::Production => [],
        };
    }

    /** True once Waffy's addresses are known for $environment, so the allowlist is enforced. */
    public static function isEnforced(Environment $environment): bool
    {
        return self::forEnvironment($environment) !== [];
    }
}
