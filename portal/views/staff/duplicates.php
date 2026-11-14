<?php /** @var Portal\Web\Ctx $c @var array $groups @var int $active */
$why = ['email' => 'same email', 'phone' => 'same phone number', 'name' => 'same name']; ?>
<div class="container-page max-w-5xl py-8">
  <h1 class="text-3xl font-bold">Possible duplicate guests</h1>
  <p class="mt-1 text-slate-600"><?= e(count($groups)) ?> <?= count($groups) === 1 ? 'group' : 'groups' ?> among <?= e($active) ?> active guests. Pick the record to keep. Its bookings and the other record's bookings end up together. You see counts only, never payment or booking details.</p>
  <?php if ($groups) { ?>
    <div class="mt-6 space-y-6">
      <?php foreach ($groups as $gi => $g) { ?>
        <section class="card card-pad" aria-labelledby="g<?= $gi ?>">
          <h2 id="g<?= $gi ?>" class="font-semibold">Group <?= $gi + 1 ?> <span class="font-normal text-slate-600">&middot; <?= e(implode(', ', array_map(fn ($r) => $why[$r] ?? $r, $g['reasons']))) ?></span></h2>
          <ul class="mt-3 grid gap-3 sm:grid-cols-2">
            <?php foreach ($g['guests'] as $x) { ?>
              <li class="rounded-lg border border-slate-200 p-3 text-sm"><p class="font-semibold"><?= e($x['fullName']) ?></p><p class="break-all text-slate-700"><?= e($x['email']) ?></p><p class="text-slate-700"><?= e($x['phone']) ?></p>
                <p class="mt-1 text-xs text-slate-600"><?= e($x['bookings']) ?> bookings &middot; <?= $x['hasAccount'] ? 'has a sign-in' : 'no sign-in' ?> &middot; added <?= e(substr((string) $x['createdAt'], 0, 10)) ?></p></li>
            <?php } ?>
          </ul>
          <?php if (count($g['guests']) === 2) { [$a, $b] = $g['guests']; ?>
            <?= form_open($c, '/staff/guests/merge', 'mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end', 'data-confirm="Merge these two guests? This cannot be undone."') ?>
              <div><label class="label" for="k<?= $gi ?>">Keep this record</label><select class="input" id="k<?= $gi ?>" name="keepGuestId"><option value="<?= e($a['guestId']) ?>"><?= e($a['fullName']) ?> (<?= e($a['email']) ?>)</option><option value="<?= e($b['guestId']) ?>"><?= e($b['fullName']) ?> (<?= e($b['email']) ?>)</option></select></div>
              <div><label class="label" for="m<?= $gi ?>">Merge into it</label><select class="input" id="m<?= $gi ?>" name="mergeGuestId"><option value="<?= e($b['guestId']) ?>"><?= e($b['fullName']) ?> (<?= e($b['email']) ?>)</option><option value="<?= e($a['guestId']) ?>"><?= e($a['fullName']) ?> (<?= e($a['email']) ?>)</option></select></div>
              <button class="btn btn-primary">Merge</button>
            </form>
          <?php } else { ?><p class="mt-3 text-sm text-slate-600">This group has <?= count($g['guests']) ?> records. Merge two at a time.</p>
            <?= form_open($c, '/staff/guests/merge', 'mt-2 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end', 'data-confirm="Merge these two guests? This cannot be undone."') ?>
              <div><label class="label" for="k<?= $gi ?>">Keep</label><select class="input" id="k<?= $gi ?>" name="keepGuestId"><?php foreach ($g['guests'] as $x) { ?><option value="<?= e($x['guestId']) ?>"><?= e($x['fullName']) ?> (<?= e($x['email']) ?>)</option><?php } ?></select></div>
              <div><label class="label" for="m<?= $gi ?>">Merge into it</label><select class="input" id="m<?= $gi ?>" name="mergeGuestId"><?php foreach (array_reverse($g['guests']) as $x) { ?><option value="<?= e($x['guestId']) ?>"><?= e($x['fullName']) ?> (<?= e($x['email']) ?>)</option><?php } ?></select></div>
              <button class="btn btn-primary">Merge</button></form><?php } ?>
        </section>
      <?php } ?>
    </div>
  <?php } else { ?><div class="card card-pad mt-6 text-center">No duplicates found. Nice and tidy.</div><?php } ?>
</div>
