<?php /** @var array $r reservation view from Booking */ ?>
<section class="card card-pad mt-6" aria-labelledby="sum-h">
  <div class="flex flex-wrap items-start justify-between gap-2">
    <h2 id="sum-h" class="text-xl font-semibold"><?= e($r['roomTypeName']) ?><?= $r['quantity'] > 1 ? ' x ' . e($r['quantity']) : '' ?></h2>
    <?= badge($r['status']) ?>
  </div>
  <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
    <div><dt class="text-slate-600">Booking code</dt><dd class="font-mono text-base font-semibold"><?= e($r['code']) ?></dd></div>
    <div><dt class="text-slate-600">Guest</dt><dd class="font-medium"><?= e($r['guestName']) ?></dd></div>
    <div><dt class="text-slate-600">Check-in</dt><dd class="font-medium"><?= e(d($r['checkIn'])) ?> from 2:00 PM</dd></div>
    <div><dt class="text-slate-600">Check-out</dt><dd class="font-medium"><?= e(d($r['checkOut'])) ?> by 12:00 noon</dd></div>
    <div><dt class="text-slate-600">Nights</dt><dd class="font-medium"><?= e(Portal\Fmt::nights($r['checkIn'], $r['checkOut'])) ?></dd></div>
    <div><dt class="text-slate-600">Guests</dt><dd class="font-medium"><?= e($r['adults']) ?></dd></div>
    <div><dt class="text-slate-600">Total</dt><dd class="text-lg font-bold text-brand-900"><?= e(php_money($r['amountMinor'])) ?></dd></div>
    <div><dt class="text-slate-600">Payment</dt><dd class="font-medium"><?= badge($r['guarantee']) ?></dd></div>
    <?php if (!empty($r['specialRequests'])) { ?><div class="sm:col-span-2"><dt class="text-slate-600">Special requests</dt><dd><?= e($r['specialRequests']) ?></dd></div><?php } ?>
  </dl>
  <p class="mt-4 text-xs text-slate-600">Free cancellation until <?= e($r['freeCancelHours']) ?> hours before arrival (check-in is at 2:00 PM Manila time). After that the first night is charged. Please bring a valid photo ID.</p>
</section>
