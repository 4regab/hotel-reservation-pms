<?php /** @var Portal\Web\Ctx $c @var array $r @var bool $waiting */
$ok = in_array($r['status'], ['CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT'], true); ?>
<div class="container-page max-w-3xl py-8">
  <nav aria-label="Booking steps" class="mb-6 no-print"><ol class="flex flex-wrap gap-2 text-sm">
    <li class="badge badge-slate">1 Choose</li><li class="badge badge-slate">2 Your details</li><li class="badge badge-slate">3 Pay</li><li class="badge <?= $ok ? 'badge-green' : 'badge-blue' ?>" aria-current="step">4 <?= $ok ? 'Confirmed' : 'Confirming' ?></li>
  </ol></nav>

  <?php if ($ok) { ?>
    <div class="alert alert-ok" role="status"><p class="text-lg font-semibold">Your booking is confirmed.</p><p class="mt-1">We sent the details to your email. Your booking code is <strong class="font-mono"><?= e($r['code']) ?></strong>.</p></div>
    <?php include __DIR__ . '/partials/booking_summary.php'; ?>
    <div class="no-print mt-6 flex flex-wrap gap-3">
      <a class="btn btn-primary" href="/account/bookings/<?= e($r['reservationId']) ?>/confirmation"><?= icon('print', 'size-5') ?> Print confirmation</a>
      <a class="btn btn-secondary" href="/account/bookings/<?= e($r['reservationId']) ?>/confirmation?download=1"><?= icon('download', 'size-5') ?> Download</a>
      <a class="btn btn-ghost" href="/account">My bookings</a>
    </div>
  <?php } elseif ($waiting) { ?>
    <div class="card card-pad" data-status-poll="/book/status/<?= e($r['reservationId']) ?>" data-status-target="CONFIRMED">
      <h1 class="text-2xl font-bold">Confirming your payment</h1>
      <p class="mt-2 text-slate-700" role="status" aria-live="polite" data-status-text>We are waiting for the payment provider to confirm. This usually takes a few seconds. Please keep this page open.</p>
      <p class="mt-4"><noscript>Reload this page in a few seconds to check again.</noscript></p>
    </div>
  <?php } else { ?>
    <div class="alert alert-warn" role="status"><p class="font-semibold">This booking is <?= e(strtolower(badge_for($r['status'])[1])) ?>.</p></div>
    <?php include __DIR__ . '/partials/booking_summary.php'; ?>
    <p class="mt-6"><a class="btn btn-primary" href="/account">My bookings</a></p>
  <?php } ?>
</div>
