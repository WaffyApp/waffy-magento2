<?php

declare(strict_types=1);

namespace Waffy\Payment\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Waffy\Ecommerce\Contract\TokenStore as TokenStoreInterface;

/**
 * Persists OAuth tokens in the waffy_token table (AES-256 encrypted).
 *
 * app/merchant tokens: keyed by clientId
 * customer tokens:     keyed by clientUserId
 */
class TokenStore implements TokenStoreInterface
{
    private const TABLE = 'waffy_token';

    public function __construct(
        private readonly ResourceConnection $connection,
        private readonly EncryptorInterface $encryptor,
    ) {}

    public function storeAppToken(string $clientId, string $token): void
    {
        $this->upsert('app', $clientId, $token);
    }

    public function getAppToken(string $clientId): ?string
    {
        return $this->fetch('app', $clientId);
    }

    public function storeMerchantToken(string $clientId, string $token): void
    {
        $this->upsert('merchant', $clientId, $token);
    }

    public function getMerchantToken(string $clientId): ?string
    {
        return $this->fetch('merchant', $clientId);
    }

    public function storeCustomerToken(string $clientUserId, string $token): void
    {
        $this->upsert('customer', $clientUserId, $token);
    }

    public function getCustomerToken(string $clientUserId): ?string
    {
        return $this->fetch('customer', $clientUserId);
    }

    /**
     * Drop every cached token.
     *
     * Used when the merchant's credentials change: those tokens belong to a
     * different Waffy client, and since the SDK only validates expiry it would
     * happily keep using them. Cheap to throw away — the warm-up and the next
     * checkout mint replacements.
     */
    public function flush(): void
    {
        $conn = $this->connection->getConnection();
        $conn->delete($this->connection->getTableName(self::TABLE));
    }

    private function upsert(string $type, string $id, string $token): void
    {
        $conn = $this->connection->getConnection();
        $table = $this->connection->getTableName(self::TABLE);
        $encrypted = $this->encryptor->encrypt($token);

        $conn->insertOnDuplicate(
            $table,
            ['entity_type' => $type, 'entity_id' => $id, 'token' => $encrypted],
            ['token'],
        );
    }

    private function fetch(string $type, string $id): ?string
    {
        $conn = $this->connection->getConnection();
        $table = $this->connection->getTableName(self::TABLE);

        $select = $conn->select()
            ->from($table, ['token'])
            ->where('entity_type = ?', $type)
            ->where('entity_id = ?', $id);

        $encrypted = $conn->fetchOne($select);
        if (!$encrypted) {
            return null;
        }

        return $this->encryptor->decrypt($encrypted) ?: null;
    }
}
