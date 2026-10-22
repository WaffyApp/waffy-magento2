<?php

declare(strict_types=1);

namespace Waffy\Payment\Model\Setup;

use Waffy\Ecommerce\Config\Environment;

/**
 * One Waffy connection — what the setup wizard collects from the merchant's env
 * file (or typed in) and, once verified, writes to system config.
 *
 * adminUsername — the client admin's E.164 mobile (stored under the historical
 *                 `client_admin_email` config key).
 * orgAccountId  — the organization's Waffy account: the contract PROVIDER.
 */
class Connection
{
    public function __construct(
        public readonly Environment $environment,
        public readonly string $idBaseUrl,
        public readonly string $authBaseUrl,
        public readonly string $apiBaseUrl,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $adminUsername,
        public readonly string $adminPassword,
        public readonly string $orgAccountId,
        public readonly ?string $organizationCode = null,
    ) {}

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'environment'       => $this->environment->value,
            'id_base_url'       => $this->idBaseUrl,
            'auth_base_url'     => $this->authBaseUrl,
            'api_base_url'      => $this->apiBaseUrl,
            'client_id'         => $this->clientId,
            'client_secret'     => $this->clientSecret,
            'admin_username'    => $this->adminUsername,
            'admin_password'    => $this->adminPassword,
            'org_account_id'    => $this->orgAccountId,
            'organization_code' => $this->organizationCode,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $get = static fn(string $key): string => is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';

        return new self(
            environment:      Environment::fromString($get('environment')),
            idBaseUrl:        $get('id_base_url'),
            authBaseUrl:      $get('auth_base_url'),
            apiBaseUrl:       $get('api_base_url'),
            clientId:         $get('client_id'),
            clientSecret:     $get('client_secret'),
            adminUsername:    $get('admin_username'),
            adminPassword:    $get('admin_password'),
            orgAccountId:     $get('org_account_id'),
            organizationCode: $get('organization_code') !== '' ? $get('organization_code') : null,
        );
    }
}
