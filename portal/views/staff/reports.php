<?php /** @var Portal\Web\Ctx $c @var ?array $occ @var ?array $rev @var ?array $ops @var array $health @var string $from @var string $to @var string $today @var array $unavailable */
use Portal\Web\Charts;
$pct = fn (float $v): string => number_format($v * 100, 1) . '%';
$whole = fn (float $v): string => number_format($v);
$php0 = fn (float $v): string => 'PHP ' . number_format($v / 100);
$kpi = function (string $name, string $value, string $target, ?bool $ok, string $how) { ?>
  <tr><th scope="row" class="font-medium"><?= e($name) ?><p class="text-xs font-normal text-ink-600"><?= e($how) ?></p></th><td class="whitespace-nowrap font-semibold"><?= e($value) ?></td><td class="whitespace-nowrap"><?= e($target) ?></td>
  <td><?= $ok === null ? '<span class="badge badge-slate">No data yet</span>' : ($ok ? '<span class="badge badge-green">On target</span>' : '<span class="badge badge-amber">Off target</span>') ?></td></tr>
<?php }; ?>
<div class="container-page max-w-6xl pb-4 pt-8 sm:pt-10">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <div><h1 class="page-title">Reports</h1><p class="mt-1 text-ink-600">Read live from the booking, billing and room systems when you open this page. Generated <?= e(dt(gmdate('Y-m-d H:i:s'))) ?>. Never used to sell rooms.</p></div>
    <form method="get" action="/staff/reports" class="flex flex-wrap items-end gap-3"><?= field('From', 'from', 'date', $from) ?><?= field('To', 'to', 'date', $to) ?><button class="btn btn-secondary">Update</button></form>
  </div>
  <?php if ($unavailable) { ?><p class="alert alert-warn mt-4" role="alert">Some figures are missing because these systems did not answer: <?= e(implode(', ', $unavailable)) ?>.</p><?php } ?>

  <?php if ($occ) { $t = $occ['totals']; ?>
    <ul class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-3 2xl:grid-cols-5" aria-label="Key figures">
      <li class="card card-pad"><p class="text-xs font-semibold uppercase text-ink-600">Occupancy</p><p class="mt-1 font-display text-3xl font-medium tracking-tight text-ink-950"><?= e($pct($t['occupancy'])) ?></p><p class="text-xs text-ink-600"><?= e($t['roomNights']) ?> of <?= e($t['roomNightsAvailable']) ?> room-nights</p></li>
      <li class="card card-pad"><p class="text-xs font-semibold uppercase text-ink-600">ADR</p><p class="mt-1 font-display text-2xl font-medium tracking-tight text-ink-950 sm:text-3xl"><?= e(php_money($t['adrMinor'])) ?></p><p class="text-xs text-ink-600">average daily rate</p></li>
      <li class="card card-pad"><p class="text-xs font-semibold uppercase text-ink-600">RevPAR</p><p class="mt-1 font-display text-2xl font-medium tracking-tight text-ink-950 sm:text-3xl"><?= e(php_money($t['revparMinor'])) ?></p><p class="text-xs text-ink-600">revenue per available room</p></li>
      <li class="card card-pad"><p class="text-xs font-semibold uppercase text-ink-600">Room revenue</p><p class="mt-1 font-display text-2xl font-medium tracking-tight text-ink-950 sm:text-3xl"><?= e($php0((float) $t['revenueMinor'])) ?></p><p class="text-xs text-ink-600">booked nights in range</p></li>
      <li class="card card-pad col-span-2 lg:col-span-1 2xl:col-span-1"><p class="text-xs font-semibold uppercase text-ink-600">Cash collected</p><p class="mt-1 font-display text-2xl font-medium tracking-tight text-ink-950 sm:text-3xl"><?= $rev ? e($php0((float) $rev['totals']['netMinor'])) : '-' ?></p><p class="text-xs text-ink-600">payments minus refunds<?= $rev ? ', to ' . e(d($rev['to'])) : '' ?></p></li>
    </ul>

    <?php
      $occBars = array_map(fn ($x) => ['label' => $x['date'], 'value' => $x['occupancy'], 'muted' => $x['date'] > $today, 'note' => $x['roomsSold'] . ' rooms'], $occ['days']);
      $revBars = array_map(fn ($x) => ['label' => $x['date'], 'value' => $x['revenueMinor'], 'muted' => $x['date'] > $today], $occ['days']);
    ?>
    <section class="card card-pad mt-6" aria-labelledby="oc-h"><h2 id="oc-h" class="text-lg font-semibold">Occupancy by night</h2><p class="text-sm text-ink-600">Light bars are nights still to come (booked so far).</p>
      <div class="mt-3"><?= Charts::bars('Occupancy by night', 'Share of rooms sold each night from ' . $occ['from'] . ' to ' . $occ['to'], $occBars, $pct, 1.0) ?></div>
      <details class="mt-3"><summary class="link cursor-pointer text-sm">Show as a table</summary><div class="table-wrap mt-2 max-h-72 overflow-y-auto"><table class="table"><thead><tr><th scope="col">Night</th><th scope="col">Rooms sold</th><th scope="col">Occupancy</th><th scope="col">ADR</th><th scope="col">Revenue</th></tr></thead><tbody>
        <?php foreach ($occ['days'] as $x) { ?><tr><td><?= e(d($x['date'])) ?></td><td><?= e($x['roomsSold']) ?> of <?= e($x['roomsAvailable']) ?></td><td><?= e($pct($x['occupancy'])) ?></td><td><?= e(php_money($x['adrMinor'])) ?></td><td><?= e(php_money($x['revenueMinor'])) ?></td></tr><?php } ?></tbody></table></div></details>
    </section>
    <div class="mt-6 grid gap-6">
      <section class="card card-pad" aria-labelledby="rv-h"><h2 id="rv-h" class="text-lg font-semibold">Room revenue by night</h2><div class="mt-3"><?= Charts::bars('Room revenue by night', 'Revenue from booked room-nights each night', $revBars, $php0, null, '#b5441a', '#e2a07a') ?></div></section>
      <section class="card card-pad" aria-labelledby="ty-h"><h2 id="ty-h" class="text-lg font-semibold">By room type</h2>
        <div class="mt-3"><?= Charts::hbars('Room-nights by room type', 'Room-nights sold per room type in the range', array_map(fn ($x) => ['label' => $x['name'], 'value' => $x['roomNights']], $occ['byRoomType']), $whole) ?></div>
        <h3 class="mt-5 text-sm font-semibold">Bookings made in range, by channel</h3>
        <div class="mt-2"><?= Charts::hbars('Bookings by channel', 'Confirmed bookings by where they came from', array_map(fn ($k, $v) => ['label' => badge_for((string) $k)[1], 'value' => $v], array_keys($occ['bookings']['bySource']), array_values($occ['bookings']['bySource'])), $whole, '#2f4d74') ?></div>
      </section>
    </div>
  <?php } ?>

  <?php if ($rev) { ?>
    <section class="card card-pad mt-6" aria-labelledby="cash-h"><h2 id="cash-h" class="text-lg font-semibold">Cash in and out by day</h2>
      <div class="mt-3"><?= Charts::bars('Net cash by day', 'Payments minus refunds each day', array_map(fn ($x) => ['label' => $x['date'], 'value' => max(0, $x['netMinor'])], $rev['days']), $php0, null, '#2f3a44') ?></div>
      <p class="mt-2 text-sm text-ink-700">Payments <strong><?= e(php_money($rev['totals']['paymentsMinor'])) ?></strong>, refunds <strong><?= e(php_money($rev['totals']['refundsMinor'])) ?></strong>.
        <?php foreach ($rev['byMethod'] as $m) { ?><span class="badge badge-slate"><?= e($m['method']) ?> <?= e(php_money($m['amountMinor'])) ?></span> <?php } ?></p>
    </section>
  <?php } ?>

  <section class="mt-8" aria-labelledby="kpi-h"><h2 id="kpi-h" class="text-xl font-semibold">Hotel targets</h2><p class="text-sm text-ink-600">The measures the hotel agreed to track, with their targets.</p>
    <div class="table-wrap mt-3"><table class="table"><caption class="sr-only">Key performance indicators</caption>
      <thead><tr><th scope="col">Measure</th><th scope="col">Now</th><th scope="col">Target</th><th scope="col">Result</th></tr></thead><tbody>
      <?php
        $k = $occ['kpis'] ?? null;
        if ($k) {
            $kpi('Oversold bookings per confirmed booking', number_format($k['oversoldRate'] * 100, 2) . '%', 'under 0.2%', $k['oversoldRate'] < 0.002, $k['oversoldNights'] . ' nights over capacity (the database also refuses it)');
            $kpi('Paid direct bookings confirmed within 2 minutes', $k['paidDirectWithin2MinRate'] === null ? '-' : $pct($k['paidDirectWithin2MinRate']), '95% or more', $k['paidDirectWithin2MinRate'] === null ? null : $k['paidDirectWithin2MinRate'] >= 0.95, $k['paidDirectWithin2Min'] . ' of ' . $k['paidDirectBookings'] . ' bookings in range');
            $kpi('Duplicate active guest records', $pct($k['duplicateRate']), 'under 2%', $k['duplicateRate'] < 0.02, $k['duplicateGuestRecords'] . ' of ' . $k['activeGuests'] . ' guests');
        }
        if ($rev) { $rc = $rev['reconciliation']; $kpi('Settled payments matched by the next business day', $rc['rate'] === null ? '-' : $pct($rc['rate']), '99% or more', $rc['rate'] === null ? null : $rc['rate'] >= 0.99, $rc['matchedOnTime'] . ' of ' . $rc['due'] . ' payments due'); }
        if ($ops) {
            $kpi('Median check-in time, queue to key', $ops['queueToKeyMedianMinutes'] === null ? '-' : $ops['queueToKeyMedianMinutes'] . ' min', '5 min or less', $ops['queueToKeyMedianMinutes'] === null ? null : $ops['queueToKeyMedianMinutes'] <= 5, $ops['queueToKeySamples'] . ' check-ins timed');
            $kpi('Median checkout to ready', $ops['checkoutToReadyMedianMinutes'] === null ? '-' : round($ops['checkoutToReadyMedianMinutes']) . ' min', '45 min or less', $ops['checkoutToReadyMedianMinutes'] === null ? null : $ops['checkoutToReadyMedianMinutes'] <= 45, $ops['checkoutToReadySamples'] . ' rooms cleaned');
        }
        $kpi('Report freshness', 'Live', 'within 5 minutes', true, 'Figures are read when this page opens');
        $up = count(array_filter($health)); $kpi('Booking system availability (right now)', $up . ' of ' . count($health) . ' services up', 'all up', $up === count($health), 'Month-long availability needs an external uptime monitor');
      ?>
    </tbody></table></div>
  </section>
</div>
