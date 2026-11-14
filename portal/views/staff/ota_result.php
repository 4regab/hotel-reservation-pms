<?php /** @var Portal\Web\Ctx $c @var array $res */ ?>
<div class="container-page max-w-4xl pb-4 pt-8 sm:pt-10">
  <h1 class="page-title">Import result</h1>
  <p class="alert <?= $res['rowsRejected'] ? 'alert-warn' : 'alert-ok' ?> mt-4" role="status"><?= e($res['rowsOk']) ?> of <?= e($res['rowsTotal']) ?> rows imported. <?= e($res['rowsRejected']) ?> rejected. Fix rejected rows in the partner's file and upload only those again. Rows already imported are skipped safely.</p>
  <div class="table-wrap mt-4"><table class="table"><caption class="sr-only">Row results</caption>
    <thead><tr><th scope="col">Line</th><th scope="col">Partner booking</th><th scope="col">Result</th><th scope="col">Why</th></tr></thead>
    <tbody><?php foreach ($res['report'] as $r) { ?><tr><td><?= e($r['line']) ?></td><td class="font-mono"><?= e($r['externalId']) ?></td><td><?= $r['result'] === 'IMPORTED' ? '<span class="badge badge-green">Imported</span>' : '<span class="badge badge-red">Rejected</span>' ?></td><td><?= e($r['reason'] ?? '') ?></td></tr><?php } ?></tbody></table></div>
  <p class="mt-6"><a class="btn btn-primary" href="/staff/ota">Back to partners</a></p>
</div>
