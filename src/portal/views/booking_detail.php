<?php /** @var Portal\Web\Ctx $c @var array $r @var array $cancel @var ?array $receipt */ ?>
<div class="container-page max-w-3xl pb-4 pt-8 sm:pt-10">
  <nav aria-label="Breadcrumb" class="flex items-center text-sm text-ink-600"><a class="link inline-flex min-h-11 items-center" href="/account">My bookings</a> <span aria-hidden="true">/</span> <?= e($r['code']) ?></nav>
  <h1 class="page-title mt-2">Booking <?= e($r['code']) ?></h1>
  <?php include __DIR__ . '/partials/booking_summary.php'; ?>

  <?php if (in_array($r['status'], ['CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT'], true)) { ?>
    <div class="mt-6 flex flex-wrap gap-3">
      <a class="btn btn-primary" href="/account/bookings/<?= e($r['reservationId']) ?>/confirmation"><?= icon('print', 'size-5') ?> Print confirmation</a>
      <a class="btn btn-secondary" href="/account/bookings/<?= e($r['reservationId']) ?>/confirmation?download=1"><?= icon('download', 'size-5') ?> Download</a>
      <a class="btn btn-secondary" href="/account/bookings/<?= e($r['reservationId']) ?>/receipt">Receipt</a>
    </div>
  <?php } elseif ($r['status'] === 'HELD') { ?>
    <p class="mt-6"><a class="btn btn-primary" href="/book/pay/<?= e($r['reservationId']) ?>">Pay to confirm</a></p>
  <?php } ?>

  <?php if ($receipt && ($receipt['payments'] || $receipt['refunds'])) { ?>
    <section class="card card-pad mt-6" aria-labelledby="pay-h"><h2 id="pay-h" class="text-lg font-semibold">Payments</h2>
      <ul class="mt-3 divide-y divide-ink-100 text-sm">
        <?php foreach ($receipt['payments'] as $p) { ?><li class="flex justify-between gap-3 py-2"><span>Payment received <?= e(dt($p['createdAt'])) ?></span><span class="whitespace-nowrap font-medium"><?= e(php_money($p['amountMinor'])) ?></span></li><?php } ?>
        <?php foreach ($receipt['refunds'] as $p) { ?><li class="flex justify-between gap-3 py-2"><span>Refund <?= badge($p['status']) ?></span><span class="whitespace-nowrap font-medium">-<?= e(php_money($p['amountMinor'])) ?></span></li><?php } ?>
      </ul>
    </section>
  <?php } ?>

  <?php if ($cancel['allowed']) { ?>
    <section class="card card-pad mt-6 border-red-200" aria-labelledby="cancel-h">
      <h2 id="cancel-h" class="text-lg font-semibold">Cancel this booking</h2>
      <?php if ($r['status'] === 'HELD') { ?>
        <p class="mt-2 text-sm text-ink-700">Nothing has been charged. Cancelling releases the room.</p>
      <?php } elseif ($cancel['free']) { ?>
        <p class="mt-2 text-sm text-ink-700">Free cancellation applies until <?= e(dt($cancel['freeUntil'])) ?>. <?= $cancel['refundMinor'] > 0 ? 'You will be refunded ' . e(php_money($cancel['refundMinor'])) . ' to your original payment method.' : '' ?></p>
      <?php } else { ?>
        <p class="mt-2 text-sm text-red-900">Free cancellation ended. The first night (<?= e(php_money($cancel['penaltyMinor'])) ?>) is kept. <?= $cancel['refundMinor'] > 0 ? 'You will be refunded ' . e(php_money($cancel['refundMinor'])) . '.' : '' ?></p>
      <?php } ?>
      <?= form_open($c, '/account/bookings/' . $r['reservationId'] . '/cancel', 'mt-4', 'data-confirm="Cancel booking ' . e($r['code']) . '? This cannot be undone."') ?>
        <button class="btn btn-danger">Cancel booking</button>
      </form>
    </section>
  <?php } ?>
</div>
