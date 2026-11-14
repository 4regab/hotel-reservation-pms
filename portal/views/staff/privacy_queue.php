<?php /** @var Portal\Web\Ctx $c @var array $rows @var string $status */
$typeLabel = ['ACCESS' => 'Copy of data', 'CORRECTION' => 'Correction', 'DELETION' => 'Deletion']; ?>
<div class="container-page max-w-5xl py-8">
  <h1 class="text-3xl font-bold">Privacy requests</h1>
  <nav aria-label="Filter" class="mt-4 flex flex-wrap gap-2"><?php foreach ([''  => 'Open', 'COMPLETED' => 'Completed', 'REJECTED' => 'Declined'] as $k => $l) { ?><a class="btn btn-sm <?= $status === $k ? 'btn-primary' : 'btn-secondary' ?>" href="/staff/admin/privacy<?= $k !== '' ? '?status=' . e($k) : '' ?>"><?= e($l) ?></a><?php } ?></nav>
  <?php if ($rows) { ?>
    <div class="table-wrap mt-4"><table class="table"><caption class="sr-only">Requests</caption>
      <thead><tr><th scope="col">Type</th><th scope="col">Person</th><th scope="col">Status</th><th scope="col">Filed</th><th scope="col">Answer due</th><th scope="col"><span class="sr-only">Open</span></th></tr></thead>
      <tbody><?php foreach ($rows as $r) { ?><tr><td class="font-medium"><?= e($typeLabel[$r['type']]) ?></td><td><?= e($r['full_name']) ?><p class="break-all text-xs text-slate-600"><?= e($r['email']) ?></p></td><td><?= badge($r['status']) ?></td><td class="whitespace-nowrap"><?= e(dt($r['created_at'])) ?></td>
        <td class="whitespace-nowrap <?= $r['overdue'] && in_array($r['status'], ['OPEN', 'IN_REVIEW'], true) ? 'font-bold text-red-800' : '' ?>"><?= e(dt($r['due_at'])) ?></td><td><a class="btn btn-secondary btn-sm" href="/staff/admin/privacy/<?= e($r['request_id']) ?>">Open</a></td></tr><?php } ?></tbody></table></div>
  <?php } else { ?><div class="card card-pad mt-4 text-center">No requests here.</div><?php } ?>
</div>
