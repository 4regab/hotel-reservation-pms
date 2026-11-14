<?php
declare(strict_types=1);

namespace Booking;

use Lib\Uuid;

/** Reservation and guest SQL. Every statement is a PDO prepared statement. */
final class BookingRepository
{
    private const SELECT = 'SELECT r.*, g.account_id AS guest_account_id FROM reservation r JOIN guest g ON g.guest_id = r.guest_id WHERE r.reservation_id = ?';

    public function __construct(private \PDO $pdo)
    {
    }

    public function roomTypes(): array
    {
        return $this->pdo->query('SELECT room_type_id, name, capacity, nightly_rate_minor, currency FROM room_type ORDER BY nightly_rate_minor')->fetchAll();
    }

    public function roomType(string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT room_type_id, name, capacity, nightly_rate_minor, currency FROM room_type WHERE room_type_id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @param array{fullName:string,email:string,phone:string} $g */
    public function upsertGuest(string $accountId, array $g): string
    {
        $st = $this->pdo->prepare('SELECT guest_id FROM guest WHERE account_id = ?');
        $st->execute([$accountId]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            return (string) $id;
        }
        $id = Uuid::v4();
        $this->pdo->prepare('INSERT INTO guest (guest_id, account_id, full_name, email, phone, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$id, $accountId, $g['fullName'], $g['email'], $g['phone']]);
        return $id;
    }

    public function guestExists(string $guestId): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM guest WHERE guest_id = ?');
        $st->execute([$guestId]);
        return $st->fetchColumn() !== false;
    }

    public function find(string $id): ?array
    {
        $st = $this->pdo->prepare(self::SELECT);
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Row lock on the reservation (take this before any inventory locks). */
    public function lock(string $id): ?array
    {
        $st = $this->pdo->prepare(self::SELECT . ' FOR UPDATE OF r');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function insert(array $r): void
    {
        $this->pdo->prepare(
            "INSERT INTO reservation (reservation_id, guest_id, room_type_id, check_in, check_out, quantity, status, guarantee, quote_minor, nightly_minor, currency, free_cancel_hours, hold_expires_at, version, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'HELD', 'NONE', ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([$r['id'], $r['guestId'], $r['roomTypeId'], $r['checkIn'], $r['checkOut'], $r['quantity'], $r['quoteMinor'], $r['nightlyMinor'], $r['currency'], $r['freeCancelHours'], $r['holdExpiresAt']]);
    }

    /** Returns the new version (caller holds the row lock). */
    public function setStatus(array $r, string $status, ?string $guarantee = null): int
    {
        $this->pdo->prepare('UPDATE reservation SET status = ?, guarantee = COALESCE(?, guarantee), version = version + 1, updated_at = UTC_TIMESTAMP() WHERE reservation_id = ?')
            ->execute([$status, $guarantee, $r['reservation_id']]);
        return (int) $r['version'] + 1;
    }

    public function insertAllocations(string $reservationId, string $roomTypeId, array $nights, int $qty, string $state): void
    {
        $st = $this->pdo->prepare('INSERT INTO allocation (reservation_id, stay_date, room_type_id, quantity, state) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), state = VALUES(state)');
        foreach ($nights as $n) {
            $st->execute([$reservationId, $n, $roomTypeId, $qty, $state]);
        }
    }

    public function setAllocationState(string $reservationId, string $state): void
    {
        $this->pdo->prepare('UPDATE allocation SET state = ? WHERE reservation_id = ?')->execute([$state, $reservationId]);
    }

    /** Holds whose 10 minutes are up. */
    public function expiredHoldIds(int $limit = 100): array
    {
        return $this->pdo->query("SELECT reservation_id FROM reservation WHERE status = 'HELD' AND hold_expires_at <= UTC_TIMESTAMP() ORDER BY hold_expires_at LIMIT " . max(1, $limit))->fetchAll(\PDO::FETCH_COLUMN);
    }
}
