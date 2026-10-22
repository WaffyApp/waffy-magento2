<?php

declare(strict_types=1);

namespace Waffy\Payment\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Waffy\Ecommerce\Dto\PreparedContract;

/**
 * Per-order state of the step-by-step Waffy checkout (table waffy_checkout).
 *
 * Every write is a column-level UPDATE: the checkout page runs `prepare` and
 * `begin` as parallel requests, and neither may overwrite the other's result.
 *
 * consent_state:
 *   unknown      → Link Customer not answered yet
 *   not_required → Waffy said no consent is needed — no OTP
 *   otp_sent     → an OTP is on its way; waiting for the buyer's code
 *   verified     → the OTP was verified
 * The ticket is only issued once this is not_required or verified.
 */
class CheckoutState
{
    private const TABLE = 'waffy_checkout';

    public const CONSENT_UNKNOWN      = 'unknown';
    public const CONSENT_NOT_REQUIRED = 'not_required';
    public const CONSENT_OTP_SENT     = 'otp_sent';
    public const CONSENT_VERIFIED     = 'verified';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EncryptorInterface $encryptor,
    ) {}

    /** @return array<string, mixed> The order's row (created on first use). */
    public function get(int $orderId): array
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $conn->insertOnDuplicate($table, ['order_id' => $orderId], ['order_id']);

        $row = $conn->fetchRow($conn->select()->from($table)->where('order_id = ?', $orderId));

        return is_array($row) ? $row : ['order_id' => $orderId, 'consent_state' => self::CONSENT_UNKNOWN];
    }

    /** The order's contract, once prepare has made it. */
    public function prepared(array $row): ?PreparedContract
    {
        $contractId  = (string) ($row['contract_id'] ?? '');
        $milestoneId = (string) ($row['milestone_id'] ?? '');
        if ($contractId === '' || $milestoneId === '') {
            return null;
        }
        $shortId = (string) ($row['short_id'] ?? '');

        return new PreparedContract($contractId, $milestoneId, $shortId !== '' ? $shortId : null);
    }

    public function ticket(array $row): ?string
    {
        $raw = (string) ($row['ticket'] ?? '');

        return $raw === '' ? null : (string) $this->encryptor->decrypt($raw);
    }

    /**
     * Update only the given columns. A `ticket` value is encrypted here.
     *
     * @param array<string, string|int|null> $values
     */
    public function update(int $orderId, array $values): void
    {
        if (array_key_exists('ticket', $values) && $values['ticket'] !== null) {
            $values['ticket'] = $this->encryptor->encrypt((string) $values['ticket']);
        }
        $this->get($orderId); // make sure the row exists
        $conn = $this->resource->getConnection();
        $conn->update($this->resource->getTableName(self::TABLE), $values, ['order_id = ?' => $orderId]);
    }

    /**
     * Orders whose unpaid contract should be removed on Waffy: flagged by a
     * failed cleanup, or prepared before $createdBefore — and in both cases
     * never paid (still new / pending_payment, or cancelled).
     *
     * @return list<array<string, mixed>>
     */
    public function contractsToDiscard(string $createdBefore, int $limit = 100): array
    {
        $conn = $this->resource->getConnection();
        $select = $conn->select()
            ->from(['w' => $this->resource->getTableName(self::TABLE)])
            ->join(['o' => $this->resource->getTableName('sales_order')], 'o.entity_id = w.order_id', [])
            ->where('w.contract_id IS NOT NULL')
            ->where('o.state IN (?)', ['new', 'pending_payment', 'canceled'])
            ->where('w.cleanup_pending = 1 OR w.created_at < ?', $createdBefore)
            ->order('w.created_at ASC')
            ->limit($limit);

        return array_values($conn->fetchAll($select));
    }
}
