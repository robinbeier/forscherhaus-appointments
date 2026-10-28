<?php defined('BASEPATH') or exit('No direct script access allowed');

/** Narrow lifecycle marker for customers created by the anonymous booking flow. */
class Public_booking_lifecycle
{
    private object $db;

    public function __construct(?object $db = null)
    {
        $this->db = $db ?? get_instance()->db;
    }

    public function fingerprint(array $customer): string
    {
        ksort($customer);
        return hash(
            'sha256',
            json_encode(
                $customer,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function lockCustomer(int $customerId): array
    {
        $row = $this->db
            ->query('SELECT * FROM `' . $this->db->dbprefix('users') . '` WHERE `id` = ? FOR UPDATE', [$customerId])
            ->row_array();
        if (!$row) {
            throw new RuntimeException('Customer disappeared during public lifecycle operation.');
        }
        return $row;
    }

    public function markNewCustomer(int $customerId): void
    {
        $customer = $this->lockCustomer($customerId);
        if (
            !$this->db->insert('public_booking_customers', [
                'customer_id' => $customerId,
                'fingerprint' => $this->fingerprint($customer),
                'create_datetime' => date('Y-m-d H:i:s'),
                'update_datetime' => date('Y-m-d H:i:s'),
            ])
        ) {
            throw new RuntimeException('Could not persist public customer marker.');
        }
    }

    /** Retire before ordinary updates; a public reschedule may restore its prior marker. */
    public function retireMarker(int $customerId): void
    {
        if (!$this->db->delete('public_booking_customers', ['customer_id' => $customerId])) {
            throw new RuntimeException('Could not retire public customer marker.');
        }
    }

    /** @return array<string,mixed>|null */
    public function captureMarker(int $customerId): ?array
    {
        $row = $this->db
            ->query(
                'SELECT * FROM `' .
                    $this->db->dbprefix('public_booking_customers') .
                    '` WHERE `customer_id` = ? FOR UPDATE',
                [$customerId],
            )
            ->row_array();
        if (!$row) {
            return null;
        }
        $customer = $this->lockCustomer($customerId);
        if (!hash_equals((string) $row['fingerprint'], $this->fingerprint($customer))) {
            $this->retireMarker($customerId);
            return null;
        }
        return $row;
    }

    public function restoreCapturedMarker(int $customerId, ?array $captured): void
    {
        if ($captured === null) {
            return;
        }
        $customer = $this->lockCustomer($customerId);
        if ((int) ($captured['customer_id'] ?? 0) !== $customerId) {
            throw new RuntimeException('Public customer marker identity changed.');
        }
        if (
            !$this->db->insert('public_booking_customers', [
                'customer_id' => $customerId,
                'fingerprint' => $this->fingerprint($customer),
                'create_datetime' => $captured['create_datetime'] ?? date('Y-m-d H:i:s'),
                'update_datetime' => date('Y-m-d H:i:s'),
            ])
        ) {
            throw new RuntimeException('Could not restore public customer marker.');
        }
    }

    /** Link only consent rows created by this request, never by identity matching. */
    public function linkConsents(array $consentIds, int $customerId): void
    {
        if ($consentIds === []) {
            return;
        }
        if (
            !$this->db
                ->where_in('id', array_map('intval', $consentIds))
                ->update('consents', ['customer_id' => $customerId])
        ) {
            throw new RuntimeException('Could not link public booking consents.');
        }
    }

    /** Acquire parents inside the cancellation transaction before its appointment lock. */
    public function lockCancellationParents(int $customerId, int $providerId, int $serviceId): void
    {
        $ids = array_values(array_unique([$customerId, $providerId]));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            if (
                !$this->db->query('SELECT `id` FROM `' . $this->db->dbprefix('users') . '` WHERE `id` = ? FOR UPDATE', [
                    $id,
                ])
            ) {
                throw new RuntimeException('Could not lock cancellation user parent.');
            }
        }
        if (
            !$this->db->query('SELECT `id` FROM `' . $this->db->dbprefix('services') . '` WHERE `id` = ? FOR UPDATE', [
                $serviceId,
            ])
        ) {
            throw new RuntimeException('Could not lock cancellation service parent.');
        }
    }

    public function cleanupAfterCancellation(int $customerId): void
    {
        $remaining = $this->db
            ->query(
                'SELECT `id` FROM `' .
                    $this->db->dbprefix('appointments') .
                    '` WHERE `id_users_customer` = ? ORDER BY `id` FOR UPDATE',
                [$customerId],
            )
            ->result_array();
        if ($remaining !== []) {
            return;
        }
        $customer = $this->db
            ->query(
                'SELECT * FROM `' .
                    $this->db->dbprefix('users') .
                    '` WHERE `id` = ? AND `id_roles` = (SELECT `id` FROM `' .
                    $this->db->dbprefix('roles') .
                    '` WHERE `slug` = ? LIMIT 1) FOR UPDATE',
                [$customerId, DB_SLUG_CUSTOMER],
            )
            ->row_array();
        $marker = $this->db
            ->query(
                'SELECT * FROM `' .
                    $this->db->dbprefix('public_booking_customers') .
                    '` WHERE `customer_id` = ? FOR UPDATE',
                [$customerId],
            )
            ->row_array();
        if (!$customer || !$marker) {
            return;
        }
        if (!hash_equals((string) $marker['fingerprint'], $this->fingerprint($customer))) {
            if (!$this->db->delete('public_booking_customers', ['customer_id' => $customerId])) {
                throw new RuntimeException('Could not retire mismatched public customer marker.');
            }
            return;
        }
        if (!$this->db->delete('users', ['id' => $customerId])) {
            throw new RuntimeException('Could not delete orphaned public customer.');
        }
    }
}
