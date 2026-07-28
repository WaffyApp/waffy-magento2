<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Config;

/**
 * The Waffy environment a platform integration talks to, and the base URLs
 * that go with it.
 *
 * The flow uses two hosts — an auth host (OAuth token + sign-up) and an API
 * host (contract endpoints) — so each case carries both. These hostnames are
 * Waffy platform facts, identical for every integration, so they live here
 * rather than being re-hardcoded in each platform's config.
 *
 * Platforms may still override either base URL explicitly (e.g. to point at a
 * staging host); this enum only supplies the per-environment defaults.
 */
enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function authBaseUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://dev-auth.waffyapp.com',
            // TODO(TBD): confirm production URLs with the backend team.
            // See project-docs/04-open-questions.md.
            self::Production => 'https://auth.waffyapp.com',
        };
    }

    public function apiBaseUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://dev-api.waffyapp.com',
            // TODO(TBD): confirm production URLs with the backend team.
            // See project-docs/04-open-questions.md.
            self::Production => 'https://api.waffyapp.com',
        };
    }

    /**
     * Resolve a stored environment string. Anything other than the explicit
     * 'production' value resolves to Sandbox — going live stays a deliberate act.
     */
    public static function fromString(?string $value): self
    {
        return $value === self::Production->value ? self::Production : self::Sandbox;
    }
}
