<?php /** @var Portal\Web\Ctx $c @var array $partners @var array $types @var array $allotments @var array $imports */ $mgr = $c->role() === 'manager'; ?>
<div class="container-page max-w-5xl py-8">
  <h1 class="text-3xl font-bold">Travel partners</h1>
  <p class="mt-1 text-slate-600">A manager sets rooms aside for each partner before the partner sells them. Importing a partner's booking file moves one reserved room to confirmed. A booking is never accepted without a reserved room.</p>

  <section class="mt-8" aria-labelledby="al-h"><h2 id="al-h" class="text-xl font-semibold">Allotments</h2>
    <?= form_open($c, '/staff/ota/allotments', 'card card-pad mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-end') ?>
      <?= select_field('Partner', 'partnerId', array_column($partners, 'name', 'partnerId'), '') ?>
      <?= select_field('Room type', 'roomTypeId', array_column($types, 'name', 'roomTypeId'), '') ?>
      <?= field('From', 'startDate', 'date', plus_days(today(), 7), 'required min="' . e(today()) . '"') ?>
      <?= field('Until', 'endDate', 'date', plus_days(today(), 14), 'required') ?>
      <?= field('Rooms per night', 'quantity', 'number', '2', 'required min="1" max="45"') ?>
      <div class="sm:col-span-2 lg:col-span-5"><button class="btn btn-primary"><?= $mgr ? 'Set rooms aside' : 'Ask manager to set rooms aside' ?></button></div>
    </form>
    <?php if ($allotments) { ?><div class="table-wrap mt-4"><table class="table"><caption class="sr-only">Allotments</caption>
      <thead><tr><th scope="col">Partner</th><th scope="col">Room type</th><th scope="col">Dates</th><th scope="col">Rooms</th><th scope="col">Used</th><th scope="col">Status</th><?php if ($mgr) { ?><th scope="col"><span class="sr-only">Actions</span></th><?php } ?></tr></thead>
      <tbody><?php foreach ($allotments as $a) { $nightsN = max(1, (int) $a['reservedNights'] ?: 1); ?>
        <tr><td><?= e($a['partnerName']) ?></td><td><?= e($a['roomTypeName']) ?></td><td class="whitespace-nowrap"><?= e(d($a['startDate'])) ?> to <?= e(d($a['endDate'])) ?></td><td><?= e($a['quantity']) ?> per night</td>
          <td><?= $a['status'] === 'APPROVED' || $a['status'] === 'RETURNED' ? e($a['usedNights']) . ' of ' . e($a['reservedNights']) . ' room-nights' : '-' ?></td><td><?= badge($a['status']) ?></td>
          <?php if ($mgr) { ?><td><?php if ($a['status'] === 'APPROVED') { echo form_open($c, '/staff/ota/allotments/' . $a['allotmentId'] . '/return', 'inline', 'data-confirm="Return the unused rooms to direct sales? The partner can no longer sell them."') . '<input type="hidden" name="back" value="/staff/ota"><button class="btn btn-secondary btn-sm">Return unused</button></form>'; } ?></td><?php } ?></tr>
      <?php } ?></tbody></table></div>
    <?php } else { ?><p class="mt-3 text-sm text-slate-600">No allotments yet.</p><?php } ?>
  </section>

  <section class="mt-10" aria-labelledby="imp-h"><h2 id="imp-h" class="text-xl font-semibold">Import bookings</h2>
    <form method="post" action="/staff/ota/import" enctype="multipart/form-data" class="card card-pad mt-3 grid gap-4 sm:grid-cols-2 sm:items-end">
      <?= csrf_field($c) ?>
      <?= select_field('Partner', 'partnerId', array_column($partners, 'name', 'partnerId'), '') ?>
      <div><label class="label" for="csv">CSV file</label><input class="input pt-2" id="csv" name="csv" type="file" accept=".csv,text/csv" required><p class="hint">Up to 500 rows and 256 KB. <a class="link" href="/staff/ota/template.csv">Download the template</a>.</p></div>
      <div class="sm:col-span-2"><button class="btn btn-primary">Import</button></div>
    </form>
    <?php if ($imports) { ?><h3 class="mt-6 font-semibold">Recent imports</h3><div class="table-wrap mt-2"><table class="table"><caption class="sr-only">Recent imports</caption>
      <thead><tr><th scope="col">When</th><th scope="col">Partner</th><th scope="col">File</th><th scope="col">Imported</th><th scope="col">Rejected</th></tr></thead>
      <tbody><?php foreach ($imports as $i) { ?><tr><td class="whitespace-nowrap"><?= e(dt($i['createdAt'])) ?></td><td><?= e($i['partnerId']) ?></td><td><?= e($i['filename']) ?></td><td><?= e($i['rowsOk']) ?></td><td><?= e($i['rowsRejected']) ?></td></tr><?php } ?></tbody></table></div><?php } ?>
  </section>
</div>
