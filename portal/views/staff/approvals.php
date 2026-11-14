<?php /** @var Portal\Web\Ctx $c @var array $refunds @var array $refundRes @var string $me @var array $guarantees @var array $conflicts @var array $overrides @var array $allotments @var array $exports */
$total = count($refunds) + count($guarantees) + count($conflicts) + count($overrides) + count($allotments) + count($exports);
$btn = fn (string $action, string $label, string $cls = 'btn-secondary', string $extra = '') => form_open($c, $action, 'inline') . $extra . '<button class="btn btn-sm ' . $cls . '">' . e($label) . '</button></form>'; ?>
<div class="container-page max-w-5xl pb-4 pt-8 sm:pt-10">
  <h1 class="page-title">Approvals</h1>
  <p class="mt-1 text-ink-600"><?= $total === 0 ? 'Nothing is waiting for you.' : e($total) . ' ' . ($total === 1 ? 'item is' : 'items are') . ' waiting for your decision.' ?></p>

  <section class="mt-8" aria-labelledby="a-ref"><h2 id="a-ref" class="text-xl font-semibold">Refunds <span class="badge badge-slate"><?= count($refunds) ?></span></h2>
    <?php if ($refunds) { ?><ul class="mt-3 space-y-3"><?php foreach ($refunds as $r) { $res = $refundRes[$r['reservationId']] ?? null; $mine = $r['requestedBy'] === $me; ?>
      <li class="card card-pad"><div class="flex flex-wrap items-start justify-between gap-3">
        <div><p class="text-lg font-semibold"><?= e(php_money($r['amountMinor'])) ?> <span class="text-sm font-normal text-ink-600">of <?= e(php_money($r['paymentMinor'])) ?> paid</span></p>
          <p class="text-sm text-ink-700">Reason: <?= e(ucwords(strtolower(str_replace('_', ' ', $r['reason'])))) ?> &middot; asked <?= e(dt($r['createdAt'])) ?> by <?= $r['requestedBy'] === 'system' ? 'the system' : 'a cashier' ?></p>
          <?php if ($res) { ?><p class="text-sm"><a class="link" href="/staff/reservations/<?= e($res['reservationId']) ?>"><?= e($res['code']) ?></a> &middot; <?= e($res['guestName']) ?> &middot; <?= badge($res['status']) ?></p><?php } ?></div>
        <?php if ($mine) { ?><p class="text-sm text-amber-900">You asked for this refund, so someone else must decide it.</p><?php } else { ?>
        <div class="flex flex-wrap items-end gap-2"><?= $btn('/staff/approvals/refunds/' . $r['refundId'] . '/approve', 'Approve refund', 'btn-primary') ?>
          <?= form_open($c, '/staff/approvals/refunds/' . $r['refundId'] . '/reject', 'flex items-end gap-2') ?><div><label class="label" for="n-<?= e($r['refundId']) ?>">Reason to decline</label><input class="input" id="n-<?= e($r['refundId']) ?>" name="note" required maxlength="200"></div><button class="btn btn-secondary btn-sm">Decline</button></form></div><?php } ?>
      </div></li><?php } ?></ul>
    <?php } else { ?><p class="mt-2 text-sm text-ink-600">No refunds waiting.</p><?php } ?></section>

  <section class="mt-8" aria-labelledby="a-g"><h2 id="a-g" class="text-xl font-semibold">Pay-at-hotel guarantees <span class="badge badge-slate"><?= count($guarantees) ?></span></h2>
    <?php if ($guarantees) { ?><ul class="mt-3 space-y-3"><?php foreach ($guarantees as $g) { ?>
      <li class="card card-pad flex flex-wrap items-center justify-between gap-3"><div><p class="font-semibold"><a class="link" href="/staff/reservations/<?= e($g['reservationId']) ?>"><?= e($g['code']) ?></a> &middot; <?= e($g['guestName']) ?></p><p class="text-sm text-ink-700"><?= e($g['roomTypeName']) ?> &middot; <?= e(d($g['checkIn'])) ?> to <?= e(d($g['checkOut'])) ?> &middot; <?= e(php_money($g['amountMinor'])) ?> &middot; hold ends <?= e(dt($g['holdExpiresAt'])) ?></p></div>
        <?= $btn('/staff/reservations/' . $g['reservationId'] . '/approve-guarantee', 'Approve pay-at-hotel', 'btn-primary', '<input type="hidden" name="back" value="/staff/approvals">') ?></li><?php } ?></ul>
    <?php } else { ?><p class="mt-2 text-sm text-ink-600">None waiting.</p><?php } ?></section>

  <section class="mt-8" aria-labelledby="a-m"><h2 id="a-m" class="text-xl font-semibold">Maintenance conflicts <span class="badge badge-slate"><?= count($conflicts) ?></span></h2>
    <?php if ($conflicts) { ?><ul class="mt-3 space-y-3"><?php foreach ($conflicts as $b) { ?>
      <li class="card card-pad"><p class="font-semibold">Room <?= e($b['roomNumber']) ?> &middot; <?= e(d($b['startDate'])) ?> to <?= e(d($b['endDate'])) ?></p><p class="text-sm text-ink-700"><?= e($b['reason']) ?></p><p class="mt-1 text-sm text-amber-900">A guest is assigned to this room, or no spare room of this type is left. Move the guest or free a room, then retry. Or decline the block.</p>
        <div class="mt-3 flex flex-wrap items-end gap-2"><?= $btn('/staff/approvals/maintenance/' . $b['blockId'] . '/retry', 'Retry block', 'btn-primary') ?>
          <?= form_open($c, '/staff/approvals/maintenance/' . $b['blockId'] . '/reject', 'flex items-end gap-2') ?><div><label class="label" for="mn-<?= e($b['blockId']) ?>">Note</label><input class="input" id="mn-<?= e($b['blockId']) ?>" name="note" maxlength="200"></div><button class="btn btn-secondary btn-sm">Decline block</button></form></div></li><?php } ?></ul>
    <?php } else { ?><p class="mt-2 text-sm text-ink-600">None waiting.</p><?php } ?></section>

  <section class="mt-8" aria-labelledby="a-o"><h2 id="a-o" class="text-xl font-semibold">Early arrivals <span class="badge badge-slate"><?= count($overrides) ?></span></h2>
    <?php if ($overrides) { ?><ul class="mt-3 space-y-3"><?php foreach ($overrides as $o) { ?>
      <li class="card card-pad flex flex-wrap items-center justify-between gap-3"><div><p class="font-semibold"><a class="link" href="/staff/reservations/<?= e($o['reservationId']) ?>">Open booking</a></p><p class="text-sm text-ink-700"><?= e($o['reason']) ?> &middot; asked <?= e(dt($o['createdAt'])) ?></p></div>
        <div class="flex gap-2"><?= $btn('/staff/approvals/overrides/' . $o['reservationId'] . '/approve', 'Approve', 'btn-primary') ?><?= $btn('/staff/approvals/overrides/' . $o['reservationId'] . '/reject', 'Decline') ?></div></li><?php } ?></ul>
    <?php } else { ?><p class="mt-2 text-sm text-ink-600">None waiting.</p><?php } ?></section>

  <section class="mt-8" aria-labelledby="a-a"><h2 id="a-a" class="text-xl font-semibold">Partner allotments <span class="badge badge-slate"><?= count($allotments) ?></span></h2>
    <?php if ($allotments) { ?><ul class="mt-3 space-y-3"><?php foreach ($allotments as $a) { ?>
      <li class="card card-pad flex flex-wrap items-center justify-between gap-3"><p><span class="font-semibold"><?= e($a['partnerName']) ?></span>: <?= e($a['quantity']) ?> x <?= e($a['roomTypeName']) ?>, <?= e(d($a['startDate'])) ?> to <?= e(d($a['endDate'])) ?></p>
        <div class="flex gap-2"><?= $btn('/staff/ota/allotments/' . $a['allotmentId'] . '/approve', 'Approve', 'btn-primary') ?><?= $btn('/staff/ota/allotments/' . $a['allotmentId'] . '/reject', 'Decline') ?></div></li><?php } ?></ul>
    <?php } else { ?><p class="mt-2 text-sm text-ink-600">None waiting.</p><?php } ?></section>

  <section class="mt-8" aria-labelledby="a-e"><h2 id="a-e" class="text-xl font-semibold">Accounting exports <span class="badge badge-slate"><?= count($exports) ?></span></h2>
    <?php if ($exports) { ?><ul class="mt-3 space-y-3"><?php foreach ($exports as $x) { $mine = $x['requestedBy'] === $me; ?>
      <li class="card card-pad flex flex-wrap items-center justify-between gap-3"><p><span class="font-mono font-semibold"><?= e($x['batchRef']) ?></span> &middot; <?= e(d($x['periodFrom'])) ?> to <?= e(d($x['periodTo'])) ?> &middot; <?= e($x['rowCount']) ?> lines &middot; net <?= e(php_money($x['totalMinor'])) ?></p>
        <?php if ($mine) { ?><span class="text-sm text-amber-900">You requested this, so someone else must approve it.</span><?php } else { ?><div class="flex gap-2"><?= $btn('/staff/approvals/exports/' . $x['exportId'] . '/approve', 'Approve', 'btn-primary') ?><?= $btn('/staff/approvals/exports/' . $x['exportId'] . '/reject', 'Reject') ?></div><?php } ?></li><?php } ?></ul>
    <?php } else { ?><p class="mt-2 text-sm text-ink-600">None waiting.</p><?php } ?></section>
</div>
