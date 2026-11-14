<?php /** @var array $r @var array $rc */
$paid = array_sum(array_column($rc['payments'], 'amountMinor'));
$refunded = array_sum(array_map(fn ($x) => $x['status'] === 'COMPLETED' ? $x['amountMinor'] : 0, $rc['refunds']));
$extras = array_sum(array_map(fn ($x) => $x['description'] === 'Room charge' ? 0 : $x['amountMinor'], $rc['charges']));
$cancelled = in_array($r['status'], ['CANCELLED', 'NO_SHOW'], true);
$total = $cancelled ? (int) $r['penaltyMinor'] : (int) $r['amountMinor'] + $extras;
$balance = max(0, $total - $paid + $refunded); ?>
<header class="flex items-center justify-between border-b border-ink-300 pb-4">
  <div class="flex items-center gap-3"><span class="block h-10 [&>svg]:h-full [&>svg]:w-auto"><?= file_get_contents(dirname(__DIR__) . '/public/assets/brand/ma-hotel-logo-light.svg') /* inline so the downloaded file keeps its logo */ ?></span><div class="hidden border-l border-ink-200 pl-3 sm:block"><p class="sr-only">MA Hotel</p><p class="text-sm text-ink-600">Receipt</p></div></div>
  <button class="btn btn-secondary no-print" type="button" data-print><?= icon('print', 'size-5') ?> Print</button>
</header>
<h1 class="page-title mt-8 text-3xl sm:text-4xl">Receipt for booking <?= e($r['code']) ?></h1>
<p class="mt-1 text-ink-700"><?= e($r['guestName']) ?> &middot; <?= e($r['roomTypeName']) ?> &middot; <?= e(d($r['checkIn'])) ?> to <?= e(d($r['checkOut'])) ?></p>
<div class="table-wrap mt-6"><table class="table table-fit [&_td:last-child]:whitespace-nowrap">
  <thead><tr><th>Item</th><th class="text-right">Amount</th></tr></thead>
  <tbody>
    <tr><td><?= $cancelled ? 'Cancellation charge' : 'Room charge (' . e(Portal\Fmt::nights($r['checkIn'], $r['checkOut'])) . ' nights)' ?></td><td class="text-right"><?= e(php_money($total - $extras)) ?></td></tr>
    <?php foreach ($rc['charges'] as $ch) { if ($ch['description'] === 'Room charge') { continue; } ?><tr><td><?= e($ch['description']) ?></td><td class="text-right"><?= e(php_money($ch['amountMinor'])) ?></td></tr><?php } ?>
    <?php foreach ($rc['payments'] as $p) { ?><tr><td>Payment <?= e(dt($p['createdAt'])) ?> <span class="text-ink-600">(<?= e($p['method'] ?: $p['provider']) ?>)</span></td><td class="text-right">-<?= e(php_money($p['amountMinor'])) ?></td></tr><?php } ?>
    <?php foreach ($rc['refunds'] as $p) { if ($p['status'] !== 'COMPLETED') { continue; } ?><tr><td>Refund <?= e(dt($p['completedAt'])) ?></td><td class="text-right">+<?= e(php_money($p['amountMinor'])) ?></td></tr><?php } ?>
    <tr class="font-semibold"><td>Balance due</td><td class="text-right"><?= e(php_money($balance)) ?></td></tr>
  </tbody>
</table></div>
<p class="mt-4 text-sm text-ink-700">Charges of <?= e(php_money($total)) ?> include <?= e(php_money(price_parts($total, 1)['vat'])) ?> VAT (12%).</p>
<p class="mt-1 text-xs text-ink-600">Amounts in Philippine pesos, taxes included. Card numbers are never stored by MA Hotel.</p>
