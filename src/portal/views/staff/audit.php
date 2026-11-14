<?php /** @var Portal\Web\Ctx $c @var array $feed @var array $f */ ?>
<div class="container-page max-w-6xl pb-4 pt-8 sm:pt-10">
  <h1 class="page-title">Audit log</h1>
  <p class="mt-1 text-ink-600">Who did what, newest first, from all four systems. Entries cannot be edited or deleted. No contact or card details are recorded here.</p>
  <form method="get" action="/staff/admin/audit" class="card card-pad mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_auto] lg:items-end" role="search">
    <?= field('Action starts with', 'action', 'text', $f['action'], 'placeholder="refund. or user. or stay."') ?>
    <?= field('From', 'from', 'date', $f['from']) ?><?= field('To', 'to', 'date', $f['to']) ?>
    <button class="btn btn-primary">Filter</button>
  </form>
  <?php if ($feed['unavailable']) { ?><p class="alert alert-warn mt-4" role="alert">Could not read the audit trail of: <?= e(implode(', ', $feed['unavailable'])) ?>. Entries from the others are shown.</p><?php } ?>
  <?php if ($feed['entries']) { ?>
    <div class="table-wrap mt-4"><table class="table"><caption class="sr-only">Audit entries</caption>
      <thead><tr><th scope="col">When (Manila)</th><th scope="col">Who</th><th scope="col">Action</th><th scope="col">Target</th><th scope="col">Detail</th><th scope="col">System</th></tr></thead>
      <tbody><?php foreach ($feed['entries'] as $r) { ?>
        <tr><td class="whitespace-nowrap"><?= e(dt(substr((string) $r['at'], 0, 19))) ?></td><td><?= e($r['actorLabel']) ?></td><td class="font-mono text-xs"><?= e($r['action']) ?></td>
          <td class="text-xs"><?= e($r['targetType']) ?> <span class="font-mono"><?= e(substr((string) $r['targetId'], 0, 8)) ?></span></td>
          <td class="max-w-xs break-words text-xs"><?= $r['detail'] ? e(json_encode($r['detail'], JSON_UNESCAPED_SLASHES)) : '' ?></td><td><span class="badge badge-slate"><?= e($r['source']) ?></span></td></tr>
      <?php } ?></tbody></table></div>
  <?php } else { ?><div class="empty mt-4">No entries match.</div><?php } ?>
</div>
