<?php

declare(strict_types=1);

namespace Waffy\Payment\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Reads all Waffy payment configuration from Magento system config.
 * Config path: payment/waffy_payment/*
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

    public function getClientId(?int $storeId = null): string
    {
        return (string) $this->getValue('client_id', $storeId);
    }

    /** Returns the decrypted client secret. */
    public function getClientSecret(?int $storeId = null): string
    {
        $encrypted = (string) $this->getValue('client_secret', $storeId);
        if ($encrypted === '') {
            return '';
        }
        return $this->encryptor->decrypt($encrypted);
    }

    /** E.164 phone number used as the PROVIDER party in every contract. */
    public function getMerchantPhone(?int $storeId = null): string
    {
        return (string) $this->getValue('merchant_phone', $storeId);
    }

    /** E.164 phone number for the BROKER party. Empty string means no broker. */
    public function getBrokerPhone(?int $storeId = null): string
    {
        return (string) $this->getValue('broker_phone', $storeId);
    }

    public function getClientAdminEmail(?int $storeId = null): string
    {
        return (string) $this->getValue('client_admin_email', $storeId);
    }

    /** Returns the decrypted client admin password. */
    public function getClientAdminPassword(?int $storeId = null): string
    {
        $encrypted = (string) $this->getValue('client_admin_password', $storeId);
        if ($encrypted === '') {
            return '';
        }
        return $this->encryptor->decrypt($encrypted);
    }

    public function getReturnPolicy(?int $storeId = null): string
    {
        return (string) $this->getValue('return_policy', $storeId) ?: 'NO_RETURN';
    }

    public function getReturnFeePayee(?int $storeId = null): string
    {
        return (string) $this->getValue('return_fee_payee', $storeId) ?: 'PROVIDER';
    }

    public function isDeliverable(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::PATH . 'is_deliverable',
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
    }

    public function isInspectable(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::PATH . 'is_inspectable',
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
    }

    public function isAcceptable(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::PATH . 'is_acceptable',
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
    }

    public function getMilestoneDeadlineDays(?int $storeId = null): int
    {
        return (int) ($this->getValue('milestone_deadline_days', $storeId) ?: 30);
    }

    public function getCategory(?int $storeId = null): string
    {
        return (string) $this->getValue('category', $storeId) ?: 'Services';
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

    /**
     * IPs/CIDR ranges allowed to call the webhook endpoint.
     * Empty array means no restriction (allow all).
     *
     * @return string[]
     */
    public function getWebhookAllowedIps(?int $storeId = null): array
    {
        $raw = (string) $this->getValue('webhook_allowed_ips', $storeId);

        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[\s,]+/', $raw) ?: [],
        )));
    }

    public function getAuthBaseUrl(?int $storeId = null): string
    {
        return rtrim((string) $this->getValue('auth_base_url', $storeId), '/') ?: 'https://dev-auth.waffyapp.com';
    }

    public function getApiBaseUrl(?int $storeId = null): string
    {
        return rtrim((string) $this->getValue('api_base_url', $storeId), '/') ?: 'https://dev-api.waffyapp.com';
    }

    private function getValue(string $field, ?int $storeId): mixed
    {
        return $this->scopeConfig->getValue(
            self::PATH . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
    }
}
