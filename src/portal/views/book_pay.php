<?php /** @var Portal\Web\Ctx $c @var array $r @var int $secondsLeft */
$live = $r['status'] === 'HELD' && $secondsLeft > 0; ?>
<div class="container-page max-w-5xl pb-4 pt-8 sm:pt-12">
  <?php $step = 3; include __DIR__ . '/partials/steps.php'; ?>
  <h1 class="page-title">Pay to confirm</h1>

  <?php if ($live) { ?>
    <div class="mt-8 grid gap-6 lg:grid-cols-12">
      <div class="panel-dark flex min-w-0 flex-col justify-between gap-6 p-6 sm:p-8 lg:col-span-5" data-countdown="<?= e($secondsLeft) ?>" data-expired-url="/book/pay/<?= e($r['reservationId']) ?>">
        <p class="flex items-center gap-2 text-sm font-medium text-ink-300"><?= icon('clock', 'size-5 text-clay-300') ?> Your room is held for</p>
        <p class="font-display text-6xl font-medium tabular-nums sm:text-7xl tracking-tight text-white sm:text-8xl" data-countdown-text aria-hidden="true"><?= e(sprintf('%d:%02d', intdiv($secondsLeft, 60), $secondsLeft % 60)) ?></p>
        <p class="sr-only" data-countdown-live role="timer" aria-live="off"></p>
        <p class="text-sm text-ink-300">minutes. <span data-countdown-note>When the time is up the room goes back on sale.</span></p>
      </div>
      <div class="card card-pad min-w-0 lg:col-span-7">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <h2 class="font-display text-2xl font-medium"><?= e($r['roomTypeName']) ?><?= $r['quantity'] > 1 ? ' x ' . e($r['quantity']) : '' ?></h2>
          <p class="text-sm text-ink-600">Booking code <span class="font-mono font-medium text-ink-950"><?= e($r['code']) ?></span></p>
        </div>
        <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
          <div class="rounded-xl bg-ink-50 p-3"><dt class="text-ink-600">Check-in</dt><dd class="mt-0.5 font-medium text-ink-950"><?= e(d($r['checkIn'])) ?> from 2:00 PM</dd></div>
          <div class="rounded-xl bg-ink-50 p-3"><dt class="text-ink-600">Check-out</dt><dd class="mt-0.5 font-medium text-ink-950"><?= e(d($r['checkOut'])) ?> by 12:00 noon</dd></div>
        </dl>
        <dl class="mt-4 space-y-2 border-t border-ink-100 pt-4 text-sm"><?= price_rows((int) $r['amountMinor'], Portal\Fmt::nights($r['checkIn'], $r['checkOut']), (int) $r['quantity']) ?></dl>
        <?= form_open($c, '/book/pay/' . $r['reservationId'], 'mt-6') ?>
          <input type="hidden" name="idem" value="<?= e(Lib\Uuid::v4()) ?>">
          <button class="btn btn-primary btn-lg w-full"><?= icon('shield', 'size-5') ?> Pay <?= e(php_money($r['amountMinor'])) ?> securely</button>
        </form>
        <p class="mt-4 text-sm text-ink-600">You pay on the payment provider's page by card, GCash or Maya. Your booking is confirmed only when the provider tells us the payment went through, not when this page loads.</p>
        <?= form_open($c, '/account/bookings/' . $r['reservationId'] . '/cancel', 'mt-4 border-t border-ink-100 pt-3') ?><button class="btn btn-ghost btn-sm">Cancel this hold</button></form>
      </div>
    </div>
  <?php } else { ?>
    <div class="alert alert-warn mt-8 p-5 sm:p-6" role="alert">
      <p class="text-base font-semibold">This hold is no longer active.</p>
      <p class="mt-1">Status: <?= badge($r['status']) ?>. If you already paid, we will confirm the booking or refund you automatically. Otherwise please search again.</p>
      <p class="mt-4 flex flex-wrap gap-3"><a class="btn btn-primary" href="/search?checkIn=<?= e(max($r['checkIn'], plus_days(today(), 1))) ?>&amp;checkOut=<?= e(max($r['checkOut'], plus_days(today(), 2))) ?>">Search again</a> <a class="btn btn-secondary" href="/account">My bookings</a></p>
    </div>
  <?php } ?>
</div>
