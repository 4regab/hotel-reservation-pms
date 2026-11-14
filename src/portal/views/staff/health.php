<?php /** @var Portal\Web\Ctx $c @var array $h @var array $emails */ ?>
<div class="container-page max-w-5xl pb-4 pt-8 sm:pt-10">
  <h1 class="page-title">System health</h1>
  <p class="mt-1 text-ink-600">Checked just now.</p>

  <h2 class="mt-6 text-xl font-semibold">Services</h2>
  <ul class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    <?php foreach ($h['services'] as $s) { ?><li class="card card-pad"><p class="font-semibold capitalize"><?= e($s['name']) ?></p><p class="mt-1"><?= $s['ok'] ? '<span class="badge badge-green">Up</span>' : '<span class="badge badge-red">Down</span>' ?> <span class="text-sm text-ink-600"><?= e($s['ms']) ?> ms</span></p></li><?php } ?>
  </ul>

  <h2 class="mt-8 text-xl font-semibold">Event delivery</h2>
  <p class="text-sm text-ink-600">Each service sends its changes to the others from an outbox. After five failed tries an event is flagged for review (never deleted).</p>
  <div class="table-wrap mt-3"><table class="table"><caption class="sr-only">Outbox delivery by service</caption>
    <thead><tr><th scope="col">Service</th><th scope="col">Pending</th><th scope="col">Needs review</th><th scope="col">Delivered</th></tr></thead>
    <tbody><?php foreach ($h['outbox'] as $svc => $o) { ?><tr><td class="capitalize"><?= e($svc) ?></td><?php if ($o) { ?><td><?= e($o['pending']) ?></td><td class="<?= $o['review'] ? 'font-bold text-red-800' : '' ?>"><?= e($o['review']) ?></td><td><?= e($o['delivered']) ?></td><?php } else { ?><td colspan="3" class="text-ink-600">Unavailable</td><?php } ?></tr><?php } ?></tbody></table></div>
  <?php foreach ($h['outbox'] as $svc => $o) { foreach (($o['stuck'] ?? []) as $s) { ?>
    <p class="alert alert-warn mt-2 text-sm"><strong><?= e($svc) ?></strong>: <?= e($s['eventType']) ?> to <?= e($s['handler']) ?> is <?= e(strtolower($s['status'])) ?> after <?= e($s['attempts']) ?> tries (<?= e($s['lastError'] ?? 'no error recorded') ?>).</p>
  <?php } } ?>

  <h2 class="mt-8 text-xl font-semibold">Email</h2>
  <p class="text-sm text-ink-600"><?= $h['emails']['live'] ? 'Sending through Resend.' : 'No Resend key is set, so emails are written to the log instead of being sent.' ?> Last 7 days: <?= e($h['emails']['sent']) ?> sent, <?= e($h['emails']['logged']) ?> logged, <?= e($h['emails']['failed']) ?> failed. Waiting to send: <?= e($h['notifications']['pending']) ?>.</p>
  <?php foreach ($h['notifications']['attention'] as $j) { ?><p class="alert alert-warn mt-2 text-sm"><?= e($j['type']) ?> has failed <?= e($j['attempts']) ?> times: <?= e($j['last_error'] ?? '') ?></p><?php } ?>
  <?php if ($emails) { ?><div class="table-wrap mt-3"><table class="table"><caption class="sr-only">Recent emails</caption>
    <thead><tr><th scope="col">When</th><th scope="col">Template</th><th scope="col">To</th><th scope="col">Subject</th><th scope="col">Status</th></tr></thead>
    <tbody><?php foreach ($emails as $m) { ?><tr><td class="whitespace-nowrap"><?= e(dt($m['created_at'])) ?></td><td class="font-mono text-xs"><?= e($m['template']) ?></td><td class="break-all"><?= e($m['to_email']) ?></td><td><?= e($m['subject']) ?></td><td><?= badge($m['status']) ?></td></tr><?php } ?></tbody></table></div><?php } ?>
</div>
