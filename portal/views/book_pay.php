<?php /** @var Portal\Web\Ctx $c @var array $r @var int $secondsLeft */
$live = $r['status'] === 'HELD' && $secondsLeft > 0; ?>
<div class="container-page max-w-3xl py-8">
  <nav aria-label="Booking steps" class="mb-6"><ol class="flex flex-wrap gap-2 text-sm">
    <li class="badge badge-slate">1 Choose</li><li class="badge badge-slate">2 Your details</li><li class="badge badge-blue" aria-current="step">3 Pay</li><li class="badge badge-slate">4 Confirmed</li>
  </ol></nav>
  <h1 class="text-3xl font-bold">Pay to confirm</h1>

  <?php if ($live) { ?>
    <div class="card card-pad mt-6 border-amber-300 bg-amber-50" data-countdown="<?= e($secondsLeft) ?>" data-expired-url="/book/pay/<?= e($r['reservationId']) ?>">
      <p class="text-sm font-semibold text-amber-900">Your room is held for</p>
      <p class="mt-1 text-4xl font-bold tabular-nums text-amber-950" data-countdown-text aria-hidden="true"><?= e(sprintf('%d:%02d', intdiv($secondsLeft, 60), $secondsLeft % 60)) ?></p>
      <p class="sr-only" data-countdown-live role="timer" aria-live="off"></p>
      <p class="mt-1 text-sm text-amber-900">minutes. <span data-countdown-note>When the time is up the room goes back on sale.</span></p>
    </div>
    <div class="card card-pad mt-6">
      <h2 class="text-lg font-semibold"><?= e($r['roomTypeName']) ?><?= $r['quantity'] > 1 ? ' x ' . e($r['quantity']) : '' ?></h2>
      <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
        <div><dt class="text-slate-600">Check-in</dt><dd class="font-medium"><?= e(d($r['checkIn'])) ?> from 2:00 PM</dd></div>
        <div><dt class="text-slate-600">Check-out</dt><dd class="font-medium"><?= e(d($r['checkOut'])) ?> by 12:00 noon</dd></div>
        <div><dt class="text-slate-600">Booking code</dt><dd class="font-mono font-medium"><?= e($r['code']) ?></dd></div>
        <div><dt class="text-slate-600">Amount to pay</dt><dd class="text-xl font-bold text-brand-900"><?= e(php_money($r['amountMinor'])) ?></dd></div>
      </dl>
      <?= form_open($c, '/book/pay/' . $r['reservationId'], 'mt-5 flex flex-wrap gap-3') ?>
        <input type="hidden" name="idem" value="<?= e(Lib\Uuid::v4()) ?>">
        <button class="btn btn-primary">Pay <?= e(php_money($r['amountMinor'])) ?> securely</button>
      </form>
      <p class="mt-3 text-sm text-slate-600">You pay on the payment provider's page by card, GCash or Maya. Your booking is confirmed only when the provider tells us the payment went through, not when this page loads.</p>
    </div>
    <?= form_open($c, '/account/bookings/' . $r['reservationId'] . '/cancel', 'mt-4') ?><button class="btn btn-ghost btn-sm">Cancel this hold</button></form>
  <?php } else { ?>
    <div class="alert alert-warn mt-6" role="alert">
      <p class="font-semibold">This hold is no longer active.</p>
      <p class="mt-1">Status: <?= badge($r['status']) ?>. If you already paid, we will confirm the booking or refund you automatically. Otherwise please search again.</p>
      <p class="mt-3"><a class="btn btn-primary" href="/search?checkIn=<?= e(max($r['checkIn'], plus_days(today(), 1))) ?>&amp;checkOut=<?= e(max($r['checkOut'], plus_days(today(), 2))) ?>">Search again</a> <a class="btn btn-secondary" href="/account">My bookings</a></p>
    </div>
  <?php } ?>
</div>
