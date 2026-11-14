<?php /** @var Portal\Web\Ctx $c @var array $r @var bool $waiting */
$ok = in_array($r['status'], ['CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT'], true); ?>
<div class="container-page max-w-4xl pb-4 pt-8 sm:pt-12">
  <?php $step = 4; $lastLabel = $ok ? 'Confirmed' : 'Confirming'; include __DIR__ . '/partials/steps.php'; ?>

  <?php if ($ok) { ?>
    <div class="panel-dark flex flex-col gap-5 p-6 sm:flex-row sm:items-center sm:p-8" role="status">
      <span class="flex size-14 shrink-0 items-center justify-center rounded-full bg-clay-600 text-white"><?= icon('check', 'size-7') ?></span>
      <div>
        <p class="font-display text-3xl font-medium tracking-tight text-white sm:text-4xl">Your booking is confirmed.</p>
        <p class="mt-2 text-ink-300">We sent the details to your email. Your booking code is <strong class="font-mono text-white"><?= e($r['code']) ?></strong>.</p>
      </div>
    </div>
    <?php include __DIR__ . '/partials/booking_summary.php'; ?>
    <div class="no-print mt-6 flex flex-wrap gap-3">
      <a class="btn btn-primary" href="/account/bookings/<?= e($r['reservationId']) ?>/confirmation"><?= icon('print', 'size-5') ?> Print confirmation</a>
      <a class="btn btn-secondary" href="/account/bookings/<?= e($r['reservationId']) ?>/confirmation?download=1"><?= icon('download', 'size-5') ?> Download</a>
      <a class="btn btn-ghost" href="/account">My bookings</a>
    </div>
  <?php } elseif ($waiting) { ?>
    <div class="card card-pad flex flex-col items-start gap-4 sm:flex-row" data-status-poll="/book/status/<?= e($r['reservationId']) ?>" data-status-target="CONFIRMED">
      <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-dusk-100 text-dusk-800"><?= icon('clock', 'size-6') ?></span>
      <div>
        <h1 class="font-display text-3xl font-medium tracking-tight">Confirming your payment</h1>
        <p class="mt-2 text-ink-700" role="status" aria-live="polite" data-status-text>We are waiting for the payment provider to confirm. This usually takes a few seconds. Please keep this page open.</p>
        <p class="mt-4"><noscript>Reload this page in a few seconds to check again.</noscript></p>
      </div>
    </div>
  <?php } else { ?>
    <div class="alert alert-warn p-5" role="status"><p class="text-base font-semibold">This booking is <?= e(strtolower(badge_for($r['status'])[1])) ?>.</p></div>
    <?php include __DIR__ . '/partials/booking_summary.php'; ?>
    <p class="mt-6"><a class="btn btn-primary" href="/account">My bookings</a></p>
  <?php } ?>
</div>
