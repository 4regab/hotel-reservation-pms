<?php
declare(strict_types=1);

namespace Booking;

/** PDO side of stock handling. The arithmetic itself lives in Lib\Inventory (pure, unit-tested). */
final class InventoryAllocator
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** Create missing night rows (autocommit, call BEFORE the booking transaction starts). */
    public function ensureNights(string $roomTypeId, array $nights): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO inventory_night (room_type_id, stay_date, capacity, blocked, held, confirmed, ota_remaining)
             SELECT room_type_id, CAST(? AS DATE), capacity, 0, 0, 0, 0 FROM room_type WHERE room_type_id = ?
                AND NOT EXISTS (SELECT 1 FROM inventory_night WITH (UPDLOCK, HOLDLOCK) WHERE room_type_id = ? AND stay_date = CAST(? AS DATE))'
        );
        foreach ($nights as $n) {
            $st->execute([$n, $roomTypeId, $roomTypeId, $n]);
        }
    }

    /** Lock all nights in date order (consistent order prevents deadlocks). Must run inside a transaction. */
    public function lock(string $roomTypeId, array $nights): array
    {
        return $this->load($roomTypeId, $nights, ' WITH (UPDLOCK, ROWLOCK)');
    }

    /** Unlocked read for availability search. Missing nights count as fully free. */
    public function peek(string $roomTypeId, array $nights, int $capacity): array
    {
        $rows = $this->load($roomTypeId, $nights, '');
        foreach ($nights as $n) {
            $rows[$n] ??= ['capacity' => $capacity, 'blocked' => 0, 'held' => 0, 'confirmed' => 0, 'ota' => 0];
        }
        return $rows;
    }

    public function save(string $roomTypeId, array $rows, array $nights): void
    {
        $st = $this->pdo->prepare('UPDATE inventory_night SET blocked = ?, held = ?, confirmed = ?, ota_remaining = ? WHERE room_type_id = ? AND stay_date = ?');
        foreach ($nights as $n) {
            $r = $rows[$n];
            $st->execute([$r['blocked'], $r['held'], $r['confirmed'], $r['ota'], $roomTypeId, $n]);
        }
    }

    private function load(string $roomTypeId, array $nights, string $lock): array
    {
        $st = $this->pdo->prepare(
            'SELECT stay_date, capacity, blocked, held, confirmed, ota_remaining FROM inventory_night' . $lock . '
              WHERE room_type_id = ? AND stay_date BETWEEN ? AND ? ORDER BY stay_date'
        );
        $st->execute([$roomTypeId, $nights[0], $nights[count($nights) - 1]]);
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $rows[$r['stay_date']] = ['capacity' => (int) $r['capacity'], 'blocked' => (int) $r['blocked'], 'held' => (int) $r['held'], 'confirmed' => (int) $r['confirmed'], 'ota' => (int) $r['ota_remaining']];
        }
        return $rows;
    }
}
