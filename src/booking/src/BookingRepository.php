<?php
declare(strict_types=1);

namespace Booking;

use Lib\Uuid;

/** Reservation and guest SQL. Every statement is a PDO prepared statement. */
final class BookingRepository
{
    private const SELECT_FROM = 'SELECT r.*, g.account_id AS guest_account_id FROM reservation r';
    private const SELECT_JOIN = ' JOIN guest g ON g.guest_id = r.guest_id WHERE r.reservation_id = ?';

    public function __construct(private \PDO $pdo)
    {
    }

    public function roomTypes(): array
    {
        return $this->pdo->query('SELECT * FROM room_type ORDER BY nightly_rate_minor')->fetchAll();
    }

    public function roomType(string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM room_type WHERE room_type_id = ?');
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
        $this->pdo->prepare('INSERT INTO guest (guest_id, account_id, full_name, email, phone, created_at) VALUES (?, ?, ?, ?, ?, DATETRUNC(SECOND, SYSUTCDATETIME()))')
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
        $st = $this->pdo->prepare(self::SELECT_FROM . self::SELECT_JOIN);
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Row lock on the reservation (take this before any inventory locks). */
    public function lock(string $id): ?array
    {
        $st = $this->pdo->prepare(self::SELECT_FROM . ' WITH (UPDLOCK, ROWLOCK)' . self::SELECT_JOIN);
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function insert(array $r): void
    {
        $this->pdo->prepare(
            "INSERT INTO reservation (reservation_id, code, guest_id, room_type_id, check_in, check_out, quantity, status, guarantee, quote_minor, nightly_minor, currency, free_cancel_hours, hold_expires_at, source, adults, special_requests, version, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'HELD', 'NONE', ?, ?, ?, ?, ?, ?, ?, ?, 1, DATETRUNC(SECOND, SYSUTCDATETIME()), DATETRUNC(SECOND, SYSUTCDATETIME()))"
        )->execute([$r['id'], self::newCode(), $r['guestId'], $r['roomTypeId'], $r['checkIn'], $r['checkOut'], $r['quantity'], $r['quoteMinor'], $r['nightlyMinor'], $r['currency'], $r['freeCancelHours'], $r['holdExpiresAt'],
            $r['source'] ?? 'DIRECT', $r['adults'] ?? 1, $r['specialRequests'] ?? null]);
    }

    /** Short confirmation code like HL7K3Q9X. No look-alike characters (0/O, 1/I). The column is unique. */
    public static function newCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $c = 'HL';
        for ($i = 0; $i < 6; $i++) {
            $c .= $alphabet[random_int(0, 31)];
        }
        return $c;
    }

    /** Record when payment arrived (event time) and when the booking became CONFIRMED. */
    public function markConfirmed(string $id, ?string $paidAtSql): void
    {
        $this->pdo->prepare('UPDATE reservation SET confirmed_at = DATETRUNC(SECOND, SYSUTCDATETIME()), paid_at = COALESCE(?, paid_at), guarantee_requested = 0 WHERE reservation_id = ?')->execute([$paidAtSql, $id]);
    }

    /** Returns the new version (caller holds the row lock). */
    public function setStatus(array $r, string $status, ?string $guarantee = null): int
    {
        $this->pdo->prepare('UPDATE reservation SET status = ?, guarantee = COALESCE(?, guarantee), version = version + 1, updated_at = DATETRUNC(SECOND, SYSUTCDATETIME()) WHERE reservation_id = ?')
            ->execute([$status, $guarantee, $r['reservation_id']]);
        return (int) $r['version'] + 1;
    }

    public function insertAllocations(string $reservationId, string $roomTypeId, array $nights, int $qty, string $state): void
    {
        $st = $this->pdo->prepare(
            'MERGE allocation WITH (HOLDLOCK) AS d
             USING (SELECT ? AS reservation_id, CAST(? AS DATE) AS stay_date, ? AS room_type_id, CAST(? AS TINYINT) AS quantity, ? AS state) AS s
                ON d.reservation_id = s.reservation_id AND d.stay_date = s.stay_date
              WHEN MATCHED THEN UPDATE SET quantity = s.quantity, state = s.state
              WHEN NOT MATCHED THEN INSERT (reservation_id, stay_date, room_type_id, quantity, state) VALUES (s.reservation_id, s.stay_date, s.room_type_id, s.quantity, s.state);'
        );
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
        return $this->pdo->query("SELECT TOP (" . max(1, $limit) . ") reservation_id FROM reservation WHERE status = 'HELD' AND hold_expires_at <= DATETRUNC(SECOND, SYSUTCDATETIME()) ORDER BY hold_expires_at")->fetchAll(\PDO::FETCH_COLUMN);
    }
}
