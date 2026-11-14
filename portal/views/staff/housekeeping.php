<?php /** @var Portal\Web\Ctx $c @var array $tasks @var array $floors @var array $byRoom @var array $types @var array $counts @var string $me */
$cols = ['OPEN' => 'To clean', 'IN_PROGRESS' => 'Being cleaned', 'CLEANED' => 'Waiting for inspection'];
$by = ['OPEN' => [], 'IN_PROGRESS' => [], 'CLEANED' => []];
foreach ($tasks as $t) { $by[$t['status']][] = $t; }
$act = function (array $room, ?array $task) use ($c, $me) {
    $id = $room['roomId'];
    $btn = fn (string $action, string $label, string $extra = '', string $cls = 'btn-secondary') => form_open($c, $action, 'inline') . $extra . '<button class="btn ' . $cls . ' btn-sm">' . e($label) . '<span class="sr-only"> room ' . e($room['roomNumber']) . '</span></button></form>';
    $st = fn (string $to) => '<input type="hidden" name="status" value="' . $to . '"><input type="hidden" name="version" value="' . e($room['version']) . '">';
    $out = '';
    if ($room['readiness'] === 'DIRTY') { $out .= $btn('/staff/housekeeping/rooms/' . $id . '/status', 'Start cleaning', $st('CLEANING'), 'btn-primary'); }
    if ($task && $task['status'] === 'IN_PROGRESS') { $out .= $btn('/staff/housekeeping/tasks/' . $task['taskId'] . '/clean', 'Mark clean', '', 'btn-primary'); }
    if ($task && $task['status'] === 'CLEANED') {
        $own = $task['assignee'] === $me && $c->role() !== 'manager';
        $out .= $own ? '<span class="text-xs text-ink-600">Another person must inspect</span>' : $btn('/staff/housekeeping/tasks/' . $task['taskId'] . '/inspect', 'Inspect and release', '', 'btn-primary');
    }
    if (in_array($room['readiness'], ['READY', 'DIRTY'], true)) { $out .= $btn('/staff/housekeeping/rooms/' . $id . '/status', 'Out of service', $st('OUT_OF_SERVICE'), 'btn-ghost'); }
    if ($room['readiness'] === 'READY') { $out .= $btn('/staff/housekeeping/rooms/' . $id . '/status', 'Mark dirty', $st('DIRTY'), 'btn-ghost'); }
    if ($room['readiness'] === 'OUT_OF_SERVICE') { $out .= $btn('/staff/housekeeping/rooms/' . $id . '/status', 'Back in service', $st('DIRTY'), 'btn-secondary'); }
    return $out;
}; ?>
<div class="container-page pb-4 pt-8 sm:pt-10">
  <div class="flex flex-wrap items-end justify-between gap-3"><h1 class="page-title">Housekeeping</h1><a class="btn btn-secondary" href="/staff/maintenance">Report a problem</a></div>
  <ul class="mt-4 flex flex-wrap gap-2 text-sm" aria-label="Room counts"><?php foreach (['READY', 'OCCUPIED', 'DIRTY', 'CLEANING', 'OUT_OF_SERVICE'] as $s) { ?><li><?= badge($s) ?> <span class="font-semibold"><?= e($counts[$s] ?? 0) ?></span></li><?php } ?></ul>

  <h2 class="mt-8 text-xl font-semibold">Task board</h2>
  <div class="mt-3 grid gap-4 lg:grid-cols-3">
    <?php foreach ($cols as $k => $label) { ?>
      <section class="card card-pad" aria-labelledby="col-<?= e($k) ?>">
        <h3 id="col-<?= e($k) ?>" class="flex items-center justify-between font-semibold"><?= e($label) ?> <span class="badge badge-slate"><?= count($by[$k]) ?></span></h3>
        <?php if ($by[$k]) { ?><ul class="mt-3 space-y-3"><?php foreach ($by[$k] as $t) { $room = null; foreach ($floors as $fl) { foreach ($fl as $rm) { if ($rm['roomId'] === $t['roomId']) { $room = $rm; } } } ?>
          <li class="rounded-lg border border-ink-200 p-3"><p class="flex items-center justify-between"><span class="text-lg font-bold">Room <?= e($t['roomNumber']) ?></span><span class="text-xs text-ink-600">Floor <?= e($t['floor']) ?></span></p>
            <p class="text-xs text-ink-600">Since <?= e(dt($t['createdAt'])) ?></p>
            <div class="mt-2 flex flex-wrap gap-2"><?= $room ? $act($room, $t) : '' ?></div></li>
        <?php } ?></ul><?php } else { ?><p class="mt-3 text-sm text-ink-600">Nothing here.</p><?php } ?>
      </section>
    <?php } ?>
  </div>

  <h2 class="mt-10 text-xl font-semibold">All rooms by floor</h2>
  <?php foreach ($floors as $floor => $rooms) { ?>
    <section class="mt-4" aria-labelledby="fl-<?= e($floor) ?>"><h3 id="fl-<?= e($floor) ?>" class="font-semibold">Floor <?= e($floor) ?></h3>
      <ul class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <?php foreach ($rooms as $rm) { $task = $byRoom[$rm['roomId']] ?? null; ?>
          <li class="card p-3"><p class="flex items-center justify-between"><span class="text-lg font-bold"><?= e($rm['roomNumber']) ?></span><?= badge($rm['readiness']) ?></p>
            <p class="text-xs text-ink-600"><?= e($types[$rm['roomTypeId']] ?? '') ?> &middot; <?= e($rm['view']) ?></p>
            <div class="mt-2 flex flex-wrap gap-1"><?= $act($rm, $task) ?></div></li>
        <?php } ?>
      </ul>
    </section>
  <?php } ?>
</div>
