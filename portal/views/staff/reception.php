<?php /** @var Portal\Web\Ctx $c @var array $rows @var string $tab @var string $q @var array $counts @var array $stays @var string $today */
$tabs = ['arrivals' => 'Arrivals', 'inhouse' => 'In house', 'departures' => 'Departures', 'upcoming' => 'Upcoming']; ?>
<div class="container-page py-8">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <div><h1 class="text-3xl font-bold">Front desk</h1><p class="mt-1 text-slate-600"><?= e(d($today)) ?></p></div>
    <a class="btn btn-primary" href="/staff/walk-in">New walk-in booking</a>
  </div>

  <form method="get" action="/staff/reception" class="mt-6 flex flex-wrap items-end gap-3" role="search">
    <div class="min-w-0 flex-1 sm:max-w-md"><label class="label" for="q">Find a booking</label><input class="input" id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Code, guest name or email"></div>
    <button class="btn btn-secondary">Search</button><?php if ($q !== '') { ?><a class="btn btn-ghost" href="/staff/reception">Clear</a><?php } ?>
  </form>

  <?php if ($q === '') { ?>
    <nav aria-label="Front desk lists" class="mt-6 flex flex-wrap gap-2">
      <?php foreach ($tabs as $k => $label) { ?>
        <a href="/staff/reception?tab=<?= e($k) ?>" class="btn btn-sm <?= $tab === $k ? 'btn-primary' : 'btn-secondary' ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?>><?= e($label) ?><?php if (isset($counts[$k])) { ?> <span class="badge <?= $tab === $k ? 'bg-white text-brand-900' : 'badge-blue' ?>"><?= e($counts[$k]) ?></span><?php } ?></a>
      <?php } ?>
    </nav>
  <?php } else { ?><p class="mt-4 text-sm text-slate-600">Results for &ldquo;<?= e($q) ?>&rdquo;</p><?php } ?>

  <?php if ($rows) { ?>
    <div class="table-wrap mt-4">
      <table class="table">
        <caption class="sr-only">Reservations</caption>
        <thead><tr><th scope="col">Code</th><th scope="col">Guest</th><th scope="col">Room</th><th scope="col">Stay</th><th scope="col">Status</th><th scope="col">Payment</th><th scope="col"><span class="sr-only">Open</span></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r) { $late = $r['status'] === 'CONFIRMED' && $r['checkIn'] < $today; ?>
            <tr>
              <td class="whitespace-nowrap font-mono font-semibold"><a class="link" href="/staff/reservations/<?= e($r['reservationId']) ?>"><?= e($r['code']) ?></a></td>
              <td class="min-w-48 max-w-xs"><?= e($r['guestName']) ?><?php if ($r['specialRequests']) { ?><p class="text-xs text-slate-600">Request: <?= e(mb_strimwidth($r['specialRequests'], 0, 60, '...')) ?></p><?php } ?></td>
              <td><?= e($r['roomTypeName']) ?><?= $r['quantity'] > 1 ? ' x ' . e($r['quantity']) : '' ?><?php if (isset($stays[$r['reservationId']])) { ?><p class="text-xs font-semibold text-brand-900">Room <?= e($stays[$r['reservationId']]) ?></p><?php } ?></td>
              <td class="whitespace-nowrap"><?= e(d($r['checkIn'])) ?> to <?= e(d($r['checkOut'])) ?></td>
              <td><?= badge($r['status']) ?><?php if ($late) { ?> <span class="badge badge-amber">Late</span><?php } ?></td>
              <td><?= badge($r['guarantee']) ?></td>
              <td><a class="btn btn-secondary btn-sm" href="/staff/reservations/<?= e($r['reservationId']) ?>">Open</a></td>
            </tr>
          <?php } ?>
        </tbody>
      </table>
    </div>
  <?php } else { ?>
    <div class="card card-pad mt-4 text-center text-slate-700"><?= $q !== '' ? 'No bookings match that search.' : 'Nothing here right now.' ?></div>
  <?php } ?>
</div>
