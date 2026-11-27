<?php
declare(strict_types=1);

namespace Booking;

use Lib\Db;

/** PDO side of stock handling. The arithmetic itself lives in Lib\Inventory (pure, unit-tested). */
final class InventoryAllocator
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** Create missing night rows (autocommit, call BEFORE the booking transaction starts). */
    public function ensureNights(string $roomTypeId, array $nights): void
    {
        foreach ($nights as $n) {
            Db::call($this->pdo, 'usp_inventory_ensureNight', ['room_type_id' => $roomTypeId, 'stay_date' => $n]);
        }
    }

    /** Lock all nights in date order (consistent order prevents deadlocks). Must run inside a transaction. */
    public function lock(string $roomTypeId, array $nights): array
    {
        return $this->load($roomTypeId, $nights, true);
    }

    /** Unlocked read for availability search. Missing nights count as fully free. */
    public function peek(string $roomTypeId, array $nights, int $capacity): array
    {
        $rows = $this->load($roomTypeId, $nights, false);
        foreach ($nights as $n) {
            $rows[$n] ??= ['capacity' => $capacity, 'blocked' => 0, 'held' => 0, 'confirmed' => 0, 'ota' => 0];
        }
        return $rows;
    }

    public function save(string $roomTypeId, array $rows, array $nights): void
    {
        foreach ($nights as $n) {
            $r = $rows[$n];
            Db::call($this->pdo, 'usp_inventory_saveNight', ['room_type_id' => $roomTypeId, 'stay_date' => $n, 'blocked' => $r['blocked'], 'held' => $r['held'], 'confirmed' => $r['confirmed'], 'ota_remaining' => $r['ota']]);
        }
    }

    private function load(string $roomTypeId, array $nights, bool $lock): array
    {
        $st = Db::call($this->pdo, 'usp_inventory_getNights', ['room_type_id' => $roomTypeId, 'from_date' => $nights[0], 'to_date' => $nights[count($nights) - 1], 'lock' => $lock ? 1 : 0]);
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $rows[$r['stay_date']] = ['capacity' => (int) $r['capacity'], 'blocked' => (int) $r['blocked'], 'held' => (int) $r['held'], 'confirmed' => (int) $r['confirmed'], 'ota' => (int) $r['ota_remaining']];
        }
        return $rows;
    }
}
