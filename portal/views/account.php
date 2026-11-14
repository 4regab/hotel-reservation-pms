<?php /** @var Portal\Web\Ctx $c @var array $upcoming @var array $past @var string $name */
$row = function (array $r) { ?>
  <li class="card card-pad flex flex-wrap items-center justify-between gap-3">
    <div class="min-w-0">
      <p class="flex flex-wrap items-center gap-2"><a class="font-display text-xl font-medium text-ink-950 hover:underline" href="/account/bookings/<?= e($r['reservationId']) ?>"><?= e($r['roomTypeName']) ?><?= $r['quantity'] > 1 ? ' x ' . e($r['quantity']) : '' ?></a> <?= badge($r['status']) ?></p>
      <p class="mt-1 text-sm text-ink-700"><?= e(d($r['checkIn'])) ?> to <?= e(d($r['checkOut'])) ?> &middot; <span class="font-mono"><?= e($r['code']) ?></span></p>
    </div>
    <div class="flex items-center gap-3"><span class="font-semibold"><?= e(php_money($r['amountMinor'])) ?></span><a class="btn btn-secondary btn-sm" href="<?= $r['status'] === 'HELD' ? '/book/pay/' . e($r['reservationId']) : '/account/bookings/' . e($r['reservationId']) ?>"><?= $r['status'] === 'HELD' ? 'Pay now' : 'View' ?></a></div>
  </li>
<?php }; ?>
<div class="container-page max-w-4xl pb-4 pt-8 sm:pt-10">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <div><h1 class="page-title">My bookings</h1><p class="mt-1 text-ink-600">Hello, <?= e(explode(' ', $name)[0]) ?>.</p></div>
    <a class="btn btn-primary" href="/search">Book a room</a>
  </div>
  <h2 class="mt-10 text-2xl font-medium">Upcoming and current</h2>
  <?php if ($upcoming) { ?><ul class="mt-3 space-y-3"><?php foreach ($upcoming as $r) { $row($r); } ?></ul>
  <?php } else { ?><div class="empty mt-3"><span class="empty-icon"><?= icon('calendar', 'size-6') ?></span><p>You have no upcoming stays.</p><a class="btn btn-primary" href="/search">Find a room</a></div><?php } ?>
  <h2 class="mt-12 text-2xl font-medium">Past and closed</h2>
  <?php if ($past) { ?><ul class="mt-3 space-y-3"><?php foreach ($past as $r) { $row($r); } ?></ul>
  <?php } else { ?><p class="mt-3 text-ink-600">Nothing here yet.</p><?php } ?>
</div>
