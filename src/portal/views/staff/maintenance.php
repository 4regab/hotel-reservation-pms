<?php /** @var Portal\Web\Ctx $c @var array $blocks @var array $rooms @var string $requestId */ ?>
<div class="container-page max-w-5xl pb-4 pt-8 sm:pt-10">
  <h1 class="page-title">Maintenance</h1>
  <p class="mt-1 text-ink-600">A blocked room is not sold for the dates you choose. A block never moves a guest who has already been given the room: those cases go to the manager.</p>
  <?php if ($rooms) { ?>
    <?= form_open($c, '/staff/maintenance', 'card card-pad mt-6 grid gap-4 sm:grid-cols-2') ?>
      <h2 class="text-lg font-semibold sm:col-span-2">Report a problem or block a room</h2>
      <input type="hidden" name="requestId" value="<?= e($requestId) ?>">
      <div class="sm:col-span-2"><label class="label" for="m-room">Room</label><select class="input" id="m-room" name="roomId" required><option value="">Choose a room</option>
        <?php foreach ($rooms as $r) { ?><option value="<?= e($r['roomId']) ?>"><?= e($r['roomNumber']) ?> (floor <?= e($r['floor']) ?>, <?= e(strtolower(str_replace('_', ' ', $r['readiness']))) ?>)</option><?php } ?></select></div>
      <?= field('From', 'startDate', 'date', today(), 'required min="' . e(today()) . '"') ?>
      <?= field('Until (the room is back on this date)', 'endDate', 'date', plus_days(today(), 1), 'required min="' . e(plus_days(today(), 1)) . '"') ?>
      <div class="sm:col-span-2"><?= textarea_field('What is wrong?', 'reason', '', 'required maxlength="200"', 'For example: bathroom tap leaks, air conditioning not cooling.') ?></div>
      <div class="sm:col-span-2"><button class="btn btn-primary">Block room</button></div>
    </form>
  <?php } ?>
  <h2 class="mt-8 text-xl font-semibold">Blocks</h2>
  <?php if ($blocks) { ?>
    <div class="table-wrap mt-3"><table class="table"><caption class="sr-only">Maintenance blocks</caption>
      <thead><tr><th scope="col">Room</th><th scope="col">Dates</th><th scope="col">Reason</th><th scope="col">Status</th><th scope="col">Reported</th></tr></thead>
      <tbody><?php foreach ($blocks as $b) { ?><tr><td class="font-bold"><?= e($b['roomNumber']) ?></td><td class="whitespace-nowrap"><?= e(d($b['startDate'])) ?> to <?= e(d($b['endDate'])) ?></td><td><?= e($b['reason']) ?><?php if ($b['decisionNote']) { ?><p class="text-xs text-ink-600">Manager: <?= e($b['decisionNote']) ?></p><?php } ?></td><td><?= badge($b['status']) ?></td><td class="whitespace-nowrap"><?= e(dt($b['createdAt'])) ?></td></tr><?php } ?></tbody>
    </table></div>
  <?php } else { ?><div class="empty mt-3">No rooms are blocked.</div><?php } ?>
</div>
