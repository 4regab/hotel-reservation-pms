<?php /** @var Portal\Web\Ctx $c @var array $upcoming @var array $past @var string $name */
$row = function (array $r) { ?>
  <li class="card card-pad flex flex-wrap items-center justify-between gap-3">
    <div class="min-w-0">
      <p class="flex flex-wrap items-center gap-2"><a class="text-lg font-semibold text-brand-900 hover:underline" href="/account/bookings/<?= e($r['reservationId']) ?>"><?= e($r['roomTypeName']) ?><?= $r['quantity'] > 1 ? ' x ' . e($r['quantity']) : '' ?></a> <?= badge($r['status']) ?></p>
      <p class="mt-1 text-sm text-slate-700"><?= e(d($r['checkIn'])) ?> to <?= e(d($r['checkOut'])) ?> &middot; <span class="font-mono"><?= e($r['code']) ?></span></p>
    </div>
    <div class="flex items-center gap-3"><span class="font-semibold"><?= e(php_money($r['amountMinor'])) ?></span><a class="btn btn-secondary btn-sm" href="<?= $r['status'] === 'HELD' ? '/book/pay/' . e($r['reservationId']) : '/account/bookings/' . e($r['reservationId']) ?>"><?= $r['status'] === 'HELD' ? 'Pay now' : 'View' ?></a></div>
  </li>
<?php }; ?>
<div class="container-page max-w-4xl py-8">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <div><h1 class="text-3xl font-bold">My bookings</h1><p class="mt-1 text-slate-600">Hello, <?= e(explode(' ', $name)[0]) ?>.</p></div>
    <a class="btn btn-primary" href="/search">Book a room</a>
  </div>
  <h2 class="mt-8 text-xl font-semibold">Upcoming and current</h2>
  <?php if ($upcoming) { ?><ul class="mt-3 space-y-3"><?php foreach ($upcoming as $r) { $row($r); } ?></ul>
  <?php } else { ?><div class="card card-pad mt-3 text-center"><p class="text-slate-700">You have no upcoming stays.</p><p class="mt-3"><a class="btn btn-primary" href="/search">Find a room</a></p></div><?php } ?>
  <h2 class="mt-10 text-xl font-semibold">Past and closed</h2>
  <?php if ($past) { ?><ul class="mt-3 space-y-3"><?php foreach ($past as $r) { $row($r); } ?></ul>
  <?php } else { ?><p class="mt-3 text-slate-600">Nothing here yet.</p><?php } ?>
</div>
