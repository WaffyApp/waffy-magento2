<?php

declare(strict_types=1);

namespace Waffy\Payment\Model\Setup;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\FlagManager;
use Waffy\Ecommerce\Config\Environment;

/**
 * Where the setup wizard keeps the Waffy connection.
 *
 *   draft  — what the merchant saved in step 2 and has not verified yet. One
 *            encrypted blob in a flag (read straight from the DB, so it never
 *            waits on the config cache). The live connection keeps taking
 *            payments while a merchant edits.
 *   active — the verified connection, in system config under
 *            payment/waffy_payment/{env}_*, which Model\Config reads.
 *
 * The wizard works at the default scope: one Waffy connection per installation.
 */
class ConnectionRepository
{
    private const FLAG_DRAFT = 'waffy_setup_draft';
    private const PATH       = 'payment/waffy_payment/';

    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly EncryptorInterface $encryptor,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly TypeListInterface $cacheTypeList,
    ) {}

    public function getDraft(): ?Connection
    {
        $raw = (string) $this->flagManager->getFlagData(self::FLAG_DRAFT);
        if ($raw === '') {
            return null;
        }
        $data = json_decode((string) $this->encryptor->decrypt($raw), true);

        return is_array($data) ? Connection::fromArray($data) : null;
    }

    public function saveDraft(Connection $connection): void
    {
        $this->flagManager->saveFlag(
            self::FLAG_DRAFT,
            $this->encryptor->encrypt((string) json_encode($connection->toArray())),
        );
    }

    public function deleteDraft(): void
    {
        $this->flagManager->deleteFlag(self::FLAG_DRAFT);
    }

    /** The verified connection at the default scope, or null when none is set up. */
    public function getActive(): ?Connection
    {
        $environment = Environment::fromString((string) $this->value('environment'));
        $env = static fn(string $field): string => $environment->value . '_' . $field;

        // A connection without the org account predates Waffy ID (the old
        // credential fields): it cannot take payments, so the wizard treats
        // the store as not set up yet and starts at step 1.
        $clientId = (string) $this->value($env('client_id'));
        if ($clientId === '' || (string) $this->value($env('org_account_id')) === '') {
            return null;
        }

        return new Connection(
            environment:      $environment,
            idBaseUrl:        (string) $this->value($env('id_base_url')) ?: $environment->idBaseUrl(),
            authBaseUrl:      (string) $this->value($env('auth_base_url')) ?: $environment->authBaseUrl(),
            apiBaseUrl:       (string) $this->value($env('api_base_url')) ?: $environment->apiBaseUrl(),
            clientId:         $clientId,
            clientSecret:     $this->decrypt((string) $this->value($env('client_secret'))),
            adminUsername:    (string) $this->value($env('client_admin_email')),
            adminPassword:    $this->decrypt((string) $this->value($env('client_admin_password'))),
            orgAccountId:     (string) $this->value($env('org_account_id')),
            organizationCode: ((string) $this->value($env('organization_code'))) ?: null,
        );
    }

    public function isActiveEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH . 'active');
    }

    /** Make $connection the live one and enable the payment method. */
    public function activate(Connection $connection): void
    {
        $env = $connection->environment->value;
        $values = [
            'environment'                    => $env,
            $env . '_id_base_url'            => $connection->idBaseUrl,
            $env . '_auth_base_url'          => $connection->authBaseUrl,
            $env . '_api_base_url'           => $connection->apiBaseUrl,
            $env . '_client_id'              => $connection->clientId,
            $env . '_client_secret'          => $this->encryptor->encrypt($connection->clientSecret),
            $env . '_client_admin_email'     => $connection->adminUsername,
            $env . '_client_admin_password'  => $this->encryptor->encrypt($connection->adminPassword),
            $env . '_org_account_id'         => $connection->orgAccountId,
            $env . '_organization_code'      => (string) $connection->organizationCode,
            'active'                         => '1',
        ];
        foreach ($values as $field => $value) {
            $this->configWriter->save(self::PATH . $field, $value);
        }

        $this->cacheTypeList->cleanType('config');
        $this->reinitableConfig->reinit();
    }

    private function value(string $field): mixed
    {
        return $this->scopeConfig->getValue(self::PATH . $field);
    }

    private function decrypt(string $encrypted): string
    {
        return $encrypted === '' ? '' : (string) $this->encryptor->decrypt($encrypted);
    }
}
