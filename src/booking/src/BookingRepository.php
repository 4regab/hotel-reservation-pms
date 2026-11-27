<?php
declare(strict_types=1);

namespace Booking;

use Lib\Db;
use Lib\Uuid;

/** Reservation and guest data access. Every call is a stored procedure run through Lib\Db::call. */
final class BookingRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function roomTypes(): array
    {
        return Db::call($this->pdo, 'usp_booking_listRoomTypes')->fetchAll();
    }

    public function roomType(string $id): ?array
    {
        return Db::call($this->pdo, 'usp_booking_getRoomType', ['room_type_id' => $id])->fetch() ?: null;
    }

    /** @param array{fullName:string,email:string,phone:string} $g */
    public function upsertGuest(string $accountId, array $g): string
    {
        $id = Db::call($this->pdo, 'usp_guest_findByAccount', ['account_id' => $accountId])->fetchColumn();
        if ($id !== false) {
            return (string) $id;
        }
        $id = Uuid::v4();
        Db::call($this->pdo, 'usp_guest_create', ['guest_id' => $id, 'account_id' => $accountId, 'full_name' => $g['fullName'], 'email' => $g['email'], 'phone' => $g['phone']]);
        return $id;
    }

    public function guestExists(string $guestId): bool
    {
        return Db::call($this->pdo, 'usp_guest_exists', ['guest_id' => $guestId])->fetchColumn() !== false;
    }

    public function find(string $id): ?array
    {
        return Db::call($this->pdo, 'usp_reservation_getById', ['reservation_id' => $id])->fetch() ?: null;
    }

    /** Row lock on the reservation (take this before any inventory locks). */
    public function lock(string $id): ?array
    {
        return Db::call($this->pdo, 'usp_reservation_lock', ['reservation_id' => $id])->fetch() ?: null;
    }

    public function insert(array $r): void
    {
        Db::call($this->pdo, 'usp_reservation_insert', [
            'reservation_id' => $r['id'], 'code' => self::newCode(), 'guest_id' => $r['guestId'], 'room_type_id' => $r['roomTypeId'], 'check_in' => $r['checkIn'], 'check_out' => $r['checkOut'],
            'quantity' => $r['quantity'], 'quote_minor' => $r['quoteMinor'], 'nightly_minor' => $r['nightlyMinor'], 'currency' => $r['currency'], 'free_cancel_hours' => $r['freeCancelHours'],
            'hold_expires_at' => $r['holdExpiresAt'], 'source' => $r['source'] ?? 'DIRECT', 'adults' => $r['adults'] ?? 1, 'special_requests' => $r['specialRequests'] ?? null,
        ]);
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
        Db::call($this->pdo, 'usp_reservation_markConfirmed', ['reservation_id' => $id, 'paid_at' => $paidAtSql]);
    }

    /** Returns the new version (caller holds the row lock). */
    public function setStatus(array $r, string $status, ?string $guarantee = null): int
    {
        Db::call($this->pdo, 'usp_reservation_setStatus', ['reservation_id' => $r['reservation_id'], 'status' => $status, 'guarantee' => $guarantee]);
        return (int) $r['version'] + 1;
    }

    public function insertAllocations(string $reservationId, string $roomTypeId, array $nights, int $qty, string $state): void
    {
        foreach ($nights as $n) {
            Db::call($this->pdo, 'usp_allocation_upsert', ['reservation_id' => $reservationId, 'stay_date' => $n, 'room_type_id' => $roomTypeId, 'quantity' => $qty, 'state' => $state]);
        }
    }

    public function setAllocationState(string $reservationId, string $state): void
    {
        Db::call($this->pdo, 'usp_allocation_setState', ['reservation_id' => $reservationId, 'state' => $state]);
    }

    /** Holds whose 10 minutes are up. */
    public function expiredHoldIds(int $limit = 100): array
    {
        return Db::call($this->pdo, 'usp_reservation_listExpiredHoldIds', ['row_limit' => max(1, $limit)])->fetchAll(\PDO::FETCH_COLUMN);
    }
}
