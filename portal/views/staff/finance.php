<?php /** @var Portal\Web\Ctx $c @var string $tab @var string $from @var string $to @var string $me */
$mgr = $c->role() === 'manager';
$tabs = ['payments' => 'Payments', 'refunds' => 'Refunds', 'exports' => 'Accounting exports']; ?>
<div class="container-page max-w-6xl pb-4 pt-8 sm:pt-10">
  <h1 class="page-title">Payments and finance</h1>
  <nav aria-label="Finance sections" class="mt-4 flex flex-wrap gap-2"><?php foreach ($tabs as $k => $l) { ?><a class="btn btn-sm <?= $tab === $k ? 'btn-primary' : 'btn-secondary' ?>" href="/staff/finance?tab=<?= e($k) ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?>><?= e($l) ?></a><?php } ?></nav>

<?php if ($tab === 'payments') { ?>
  <form method="get" action="/staff/finance" class="mt-6 flex flex-wrap items-end gap-3"><input type="hidden" name="tab" value="payments">
    <?= field('From', 'from', 'date', $from) ?><?= field('To', 'to', 'date', $to) ?>
    <div><label class="label" for="show">Show</label><select class="input" id="show" name="show"><option value="">All payments</option><option value="open"<?= $show === 'open' ? ' selected' : '' ?>>Not matched yet</option></select></div>
    <button class="btn btn-secondary">Apply</button>
  </form>
  <?= form_open($c, '/staff/finance/reconcile-batch', 'mt-4 flex flex-wrap items-end gap-3', 'data-confirm="Mark every payment up to this date as matched with the bank or provider statement?"') ?>
    <?= field('Match everything up to and including', 'date', 'date', plus_days(today(), -1), 'required') ?><button class="btn btn-secondary">Mark all matched</button>
  </form>
  <?php if ($payments) { ?>
    <div class="table-wrap mt-4"><table class="table"><caption class="sr-only">Payments</caption>
      <thead><tr><th scope="col">Date (Manila)</th><th scope="col">Booking</th><th scope="col">How</th><th scope="col">Amount</th><th scope="col">Status</th><th scope="col">Matched</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody><?php foreach ($payments as $p) { [$code, $name] = $codes[$p['reservationId']] ?? ['?', '']; ?>
        <tr><td class="whitespace-nowrap"><?= e(d($p['day'])) ?></td>
          <td><a class="link font-mono" href="/staff/reservations/<?= e($p['reservationId']) ?>"><?= e($code) ?></a><p class="text-xs text-ink-600"><?= e($name) ?></p></td>
          <td><?= e($p['method'] ?: $p['provider']) ?><p class="break-all font-mono text-xs text-ink-600"><?= e($p['providerPaymentId']) ?></p></td>
          <td class="whitespace-nowrap font-semibold"><?= e(php_money($p['amountMinor'])) ?></td><td><?= badge($p['status']) ?><?php if ($p['refundStatus']) { ?> <span class="badge badge-amber">Refund <?= e(strtolower($p['refundStatus'])) ?></span><?php } ?></td>
          <td><?= $p['reconciledAt'] ? '<span class="badge badge-green">Yes</span>' : ($p['status'] === 'CAPTURED' ? '<span class="badge badge-amber">No</span>' : '-') ?></td>
          <td><div class="flex flex-wrap gap-2">
            <?php if ($p['status'] === 'CAPTURED' && !$p['reconciledAt']) { echo form_open($c, '/staff/finance/reconcile/' . $p['paymentId'], 'inline') . '<button class="btn btn-secondary btn-sm">Match<span class="sr-only"> payment ' . e($code) . '</span></button></form>'; } ?>
            <?php if ($p['status'] === 'CAPTURED' && !$p['refundStatus']) { ?><details><summary class="btn btn-ghost btn-sm cursor-pointer">Refund</summary>
              <?= form_open($c, '/staff/finance/refunds', 'mt-2 w-64 space-y-2 rounded-lg border border-ink-200 bg-white p-3 shadow') ?>
                <input type="hidden" name="paymentId" value="<?= e($p['paymentId']) ?>">
                <?= field('Amount (PHP)', 'amount', 'text', number_format($p['amountMinor'] / 100, 2, '.', ''), 'required inputmode="decimal"') ?>
                <?= select_field('Reason', 'reason', ['GOODWILL' => 'Goodwill', 'OVERCHARGE' => 'Overcharge', 'DUPLICATE_PAYMENT' => 'Duplicate payment', 'SERVICE_ISSUE' => 'Service issue'], 'GOODWILL') ?>
                <button class="btn btn-primary btn-sm w-full">Ask manager to approve</button></form></details><?php } ?>
          </div></td></tr>
      <?php } ?></tbody></table></div>
  <?php } else { ?><div class="empty mt-4">No payments in this range.</div><?php } ?>

<?php } elseif ($tab === 'refunds') { ?>
  <p class="mt-4 text-sm text-ink-600">A refund request is approved by a manager who did not ask for it. Cashiers cannot approve their own requests.</p>
  <?php if ($refunds) { ?><div class="table-wrap mt-4"><table class="table"><caption class="sr-only">Refunds</caption>
    <thead><tr><th scope="col">Asked</th><th scope="col">Amount</th><th scope="col">Reason</th><th scope="col">Status</th><th scope="col">Note</th></tr></thead>
    <tbody><?php foreach ($refunds as $r) { ?><tr><td class="whitespace-nowrap"><?= e(dt($r['createdAt'])) ?></td><td class="font-semibold"><?= e(php_money($r['amountMinor'])) ?></td><td><?= e(ucwords(strtolower(str_replace('_', ' ', $r['reason'])))) ?></td><td><?= badge($r['status']) ?></td><td><?= e($r['decisionNote'] ?? '') ?></td></tr><?php } ?></tbody></table></div>
  <?php } else { ?><div class="empty mt-4">No refunds yet.</div><?php } ?>

<?php } else { ?>
  <p class="mt-4 text-sm text-ink-600">An export covers closed days only (up to yesterday). A manager who did not request it must approve it before it can be downloaded. Each line carries the payment or refund id, so a re-sent file can be matched.</p>
  <?= form_open($c, '/staff/finance/exports', 'card card-pad mt-4 flex flex-wrap items-end gap-3') ?>
    <?= field('From', 'from', 'date', plus_days(today(), -7), 'required max="' . e(plus_days(today(), -1)) . '"') ?><?= field('To', 'to', 'date', plus_days(today(), -1), 'required max="' . e(plus_days(today(), -1)) . '"') ?><button class="btn btn-primary">Prepare export</button>
  </form>
  <?php if ($exports) { ?><div class="table-wrap mt-4"><table class="table"><caption class="sr-only">Exports</caption>
    <thead><tr><th scope="col">Batch</th><th scope="col">Period</th><th scope="col">Lines</th><th scope="col">Net total</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Download</span></th></tr></thead>
    <tbody><?php foreach ($exports as $x) { ?><tr><td class="font-mono text-xs"><?= e($x['batchRef']) ?><p class="break-all text-ink-600">sha256 <?= e(substr($x['checksum'], 0, 12)) ?>...</p></td><td class="whitespace-nowrap"><?= e(d($x['periodFrom'])) ?> to <?= e(d($x['periodTo'])) ?></td><td><?= e($x['rowCount']) ?></td><td class="font-semibold"><?= e(php_money($x['totalMinor'])) ?></td><td><?= badge($x['status']) ?></td>
      <td><?php if ($x['status'] === 'APPROVED') { ?><a class="btn btn-primary btn-sm" href="/staff/finance/exports/<?= e($x['exportId']) ?>/download"><?= icon('download', 'size-4') ?> CSV<span class="sr-only"> <?= e($x['batchRef']) ?></span></a><?php } elseif ($x['status'] === 'REQUESTED') { echo $mgr && $x['requestedBy'] !== $me ? '<a class="link" href="/staff/approvals">Approve</a>' : '<span class="text-xs text-ink-600">Waiting for a manager</span>'; } ?></td></tr><?php } ?></tbody></table></div>
  <?php } else { ?><div class="empty mt-4">No exports yet.</div><?php } ?>
<?php } ?>
</div>
