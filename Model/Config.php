<?php

declare(strict_types=1);

namespace Waffy\Payment\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Waffy\Ecommerce\Config\Environment;

/**
 * Reads the Waffy payment configuration from Magento system config.
 * Config path: payment/waffy_payment/*
 *
 * The Waffy connection (client, admin, organization account, hosts) is written
 * by the setup wizard (Stores → Waffy setup), one set per environment:
 * {sandbox|production}_{field}. Everything else that used to be a setting
 * (return policy, category, deadline, …) is now an SDK default, the same on
 * every platform.
 */
class Config
{
    private const PATH = 'payment/waffy_payment/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
    ) {}

    public function isActive(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::PATH . 'active',
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
    }

    public function getTitle(?int $storeId = null): string
    {
        return (string) $this->getValue('title', $storeId);
    }

    /** 'sandbox' or 'production'. Defaults to sandbox — going live is an explicit act. */
    public function getEnvironment(?int $storeId = null): string
    {
        return Environment::fromString((string) $this->getValue('environment', $storeId))->value;
    }

    public function getEnvironmentEnum(?int $storeId = null): Environment
    {
        return Environment::fromString($this->getEnvironment($storeId));
    }

    public function isSandbox(?int $storeId = null): bool
    {
        return $this->getEnvironmentEnum($storeId) === Environment::Sandbox;
    }

    public function getClientId(?int $storeId = null): string
    {
        return (string) $this->getEnvValue('client_id', $storeId);
    }

    /** Returns the decrypted client secret. */
    public function getClientSecret(?int $storeId = null): string
    {
        return $this->decrypt((string) $this->getEnvValue('client_secret', $storeId));
    }

    /** The client admin's username — an E.164 mobile on Waffy ID. */
    public function getClientAdminEmail(?int $storeId = null): string
    {
        return (string) $this->getEnvValue('client_admin_email', $storeId);
    }

    /** Returns the decrypted client admin password. */
    public function getClientAdminPassword(?int $storeId = null): string
    {
        return $this->decrypt((string) $this->getEnvValue('client_admin_password', $storeId));
    }

    /** The organization's Waffy account id — the contract PROVIDER (`org_account_id`). */
    public function getOrgAccountId(?int $storeId = null): string
    {
        return (string) $this->getEnvValue('org_account_id', $storeId);
    }

    public function getOrganizationCode(?int $storeId = null): string
    {
        return (string) $this->getEnvValue('organization_code', $storeId);
    }

    /** From the merchant's env file; the SDK's Environment default when typed in. */
    public function getIdBaseUrl(?int $storeId = null): string
    {
        return rtrim((string) $this->getEnvValue('id_base_url', $storeId), '/')
            ?: $this->getEnvironmentEnum($storeId)->idBaseUrl();
    }

    public function getAuthBaseUrl(?int $storeId = null): string
    {
        return rtrim((string) $this->getEnvValue('auth_base_url', $storeId), '/')
            ?: $this->getEnvironmentEnum($storeId)->authBaseUrl();
    }

    public function getApiBaseUrl(?int $storeId = null): string
    {
        return rtrim((string) $this->getEnvValue('api_base_url', $storeId), '/')
            ?: $this->getEnvironmentEnum($storeId)->apiBaseUrl();
    }

    public function getPaymentType(?int $storeId = null): string
    {
        return (string) $this->getValue('payment_type', $storeId) ?: 'PURCHASE';
    }

    /**
     * Store business name from Admin → Stores → Configuration → General → Store Information → Store Name.
     * Set it there — it appears as the contract title on the Waffy payment page.
     */
    public function getStoreName(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE,
            $storeId,
        ) ?: 'Store';
    }

    /** True when the gateway is enabled and every credential checkout needs is present. */
    public function hasCompleteCredentials(?int $storeId = null): bool
    {
        return $this->isActive($storeId)
            && $this->getClientId($storeId) !== ''
            && $this->getClientSecret($storeId) !== ''
            && $this->getClientAdminEmail($storeId) !== ''
            && $this->getClientAdminPassword($storeId) !== ''
            && $this->getOrgAccountId($storeId) !== '';
    }

    private function decrypt(string $encrypted): string
    {
        return $encrypted === '' ? '' : (string) $this->encryptor->decrypt($encrypted);
    }

    private function getValue(string $field, ?int $storeId): mixed
    {
        return $this->scopeConfig->getValue(
            self::PATH . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
    }

    /** Reads the field belonging to the active environment (sandbox_* / production_*). */
    private function getEnvValue(string $field, ?int $storeId): mixed
    {
        return $this->getValue($this->getEnvironment($storeId) . '_' . $field, $storeId);
    }
}
