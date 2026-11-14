<?php
declare(strict_types=1);

use Lib\Dates;
use Lib\InsufficientStock;
use Lib\Inventory;

/** @return array<string,array<string,int>> rows for the given nights */
$rows = static function (array $nights, int $capacity): array {
    $r = [];
    foreach ($nights as $n) {
        $r[$n] = ['capacity' => $capacity, 'blocked' => 0, 'held' => 0, 'confirmed' => 0, 'ota' => 0];
    }
    return $r;
};
$invariant = static function (array $rows): void {
    foreach ($rows as $n => $r) {
        foreach (['blocked', 'held', 'confirmed', 'ota'] as $b) {
            check($r[$b] >= 0, "negative {$b} on {$n}");
        }
        check($r['blocked'] + $r['held'] + $r['confirmed'] + $r['ota'] <= $r['capacity'], "over capacity on {$n}");
    }
};

return [
    'nights run from check-in up to but excluding check-out' => function (): void {
        same(['2026-12-01', '2026-12-02'], Dates::nights('2026-12-01', '2026-12-03'), 'nights');
        throws(fn () => Dates::nights('2026-12-03', '2026-12-03'), InvalidArgumentException::class);
        throws(fn () => Dates::nights('2026-12-03', '2026-12-01'), InvalidArgumentException::class);
    },
    'reserve takes every night of the stay' => function () use ($rows): void {
        $n = Dates::nights('2026-12-01', '2026-12-04');
        $after = Inventory::reserve($rows($n, 20), $n, 2, 'held');
        foreach ($n as $night) {
            same(2, $after[$night]['held'], "held on {$night}");
        }
    },
    'all-or-nothing: one full night rejects the whole stay and changes nothing' => function () use ($rows): void {
        $n = Dates::nights('2026-12-01', '2026-12-04');
        $start = $rows($n, 3);
        $start['2026-12-03']['confirmed'] = 3;   // last night is sold out
        throws(fn () => Inventory::reserve($start, $n, 1, 'held'), InsufficientStock::class, 409);
        same(0, $start['2026-12-01']['held'], 'input must be untouched');
        try {
            Inventory::reserve($start, $n, 1, 'held');
        } catch (InsufficientStock $e) {
            same('2026-12-03', $e->night, 'reports the blocking night');
        }
    },
    'never exceeds capacity: 20 Standard rooms sell exactly 20 times' => function () use ($rows, $invariant): void {
        $n = Dates::nights('2026-12-01', '2026-12-03');
        $state = $rows($n, 20);
        $sold = 0;
        for ($i = 0; $i < 30; $i++) {
            try {
                $state = Inventory::reserve($state, $n, 1, 'held');
                $sold++;
            } catch (InsufficientStock) {
            }
            $invariant($state);
        }
        same(20, $sold, 'sold');
    },
    'a multi-room request larger than what is left is refused whole' => function () use ($rows): void {
        $n = ['2026-12-01'];
        $state = Inventory::reserve($rows($n, 10), $n, 8, 'confirmed');
        throws(fn () => Inventory::reserve($state, $n, 3, 'held'), InsufficientStock::class);
        same(2, Inventory::available($state['2026-12-01']), 'two rooms remain');
    },
    'blocked, held, confirmed and OTA stock all count against capacity' => function () use ($rows): void {
        $n = ['2026-12-01'];
        $s = $rows($n, 10);
        $s = Inventory::reserve($s, $n, 3, 'blocked');
        $s = Inventory::reserve($s, $n, 3, 'held');
        $s = Inventory::reserve($s, $n, 2, 'confirmed');
        $s = Inventory::reserve($s, $n, 1, 'ota');
        same(1, Inventory::available($s['2026-12-01']), 'left');
        throws(fn () => Inventory::reserve($s, $n, 2, 'held'), InsufficientStock::class);
    },
    'overlapping stays compete only on the shared night' => function () use ($rows): void {
        $all = Dates::nights('2026-12-01', '2026-12-06');
        $s = $rows($all, 1);
        $a = Dates::nights('2026-12-01', '2026-12-04');
        $b = Dates::nights('2026-12-03', '2026-12-06');
        $s = Inventory::reserve($s, $a, 1, 'confirmed');
        throws(fn () => Inventory::reserve($s, $b, 1, 'held'), InsufficientStock::class);
        $c = Dates::nights('2026-12-04', '2026-12-06');   // starts on A's check-out day: free
        $s = Inventory::reserve($s, $c, 1, 'held');
        same(1, $s['2026-12-05']['held'], 'back-to-back stay allowed');
    },
    'release and move keep counters non-negative' => function () use ($rows, $invariant): void {
        $n = Dates::nights('2026-12-01', '2026-12-03');
        $s = Inventory::reserve($rows($n, 5), $n, 2, 'held');
        $s = Inventory::move($s, $n, 2, 'held', 'confirmed');
        same(0, $s['2026-12-01']['held'], 'held after move');
        same(2, $s['2026-12-01']['confirmed'], 'confirmed after move');
        $s = Inventory::release($s, $n, 2, 'confirmed');
        $invariant($s);
        throws(fn () => Inventory::release($s, $n, 1, 'confirmed'), LogicException::class);
        throws(fn () => Inventory::move($s, $n, 1, 'held', 'confirmed'), LogicException::class);
    },
    'bad input is rejected' => function () use ($rows): void {
        $n = ['2026-12-01'];
        throws(fn () => Inventory::reserve($rows($n, 5), $n, 0, 'held'), InvalidArgumentException::class);
        throws(fn () => Inventory::reserve($rows($n, 5), $n, 1, 'nonsense'), InvalidArgumentException::class);
        throws(fn () => Inventory::reserve($rows($n, 5), [], 1, 'held'), InvalidArgumentException::class);
        throws(fn () => Inventory::reserve($rows($n, 5), ['2026-12-01', '2026-12-01'], 1, 'held'), InvalidArgumentException::class);
    },
];
