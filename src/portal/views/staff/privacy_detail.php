<?php /** @var Portal\Web\Ctx $c @var array $q */
$open = in_array($q['status'], ['OPEN', 'IN_REVIEW'], true);
$what = ['ACCESS' => 'Send the person a file with their account, bookings and emails.', 'CORRECTION' => 'Apply the corrected details to the account and the guest record.', 'DELETION' => 'Remove the name and contacts from the account and the guest record. Stay and payment facts stay for finance. Refused while a booking is still live.'][$q['type']]; ?>
<div class="container-page max-w-3xl pb-4 pt-8 sm:pt-10">
  <nav aria-label="Breadcrumb" class="flex items-center text-sm text-ink-600"><a class="link inline-flex min-h-11 items-center" href="/staff/admin/privacy">Privacy requests</a> <span aria-hidden="true">/</span> <?= e(ucfirst(strtolower($q['type']))) ?></nav>
  <h1 class="page-title mt-2"><?= e(ucfirst(strtolower($q['type']))) ?> request <?= badge($q['status']) ?></h1>
  <dl class="card card-pad mt-6 grid gap-4 text-sm sm:grid-cols-2">
    <div><dt class="text-ink-600">Person</dt><dd class="font-medium"><?= e($q['full_name']) ?></dd></div>
    <div><dt class="text-ink-600">Account email</dt><dd class="break-all"><?= e($q['email']) ?></dd></div>
    <div><dt class="text-ink-600">Filed</dt><dd><?= e(dt($q['created_at'])) ?> (identity verified by sign-in)</dd></div>
    <div><dt class="text-ink-600">Answer due</dt><dd class="font-medium"><?= e(dt($q['due_at'])) ?></dd></div>
    <?php if ($q['correction']) { ?><div class="sm:col-span-2"><dt class="text-ink-600">Corrected details asked for</dt><dd>Name: <strong><?= e($q['correction']['fullName']) ?></strong> &middot; Email: <strong><?= e($q['correction']['email']) ?></strong> &middot; Phone: <strong><?= e($q['correction']['phone']) ?></strong><?= !empty($q['correction']['note']) ? '<br>' . e($q['correction']['note']) : '' ?></dd></div>
    <?php } elseif ($q['details'] && $q['details'] !== '[removed]') { ?><div class="sm:col-span-2"><dt class="text-ink-600">Note from the person</dt><dd><?= e($q['details']) ?></dd></div><?php } ?>
    <?php if ($q['resolution_note']) { ?><div class="sm:col-span-2"><dt class="text-ink-600">Your note</dt><dd><?= e($q['resolution_note']) ?></dd></div><?php } ?>
  </dl>
  <?php if ($open) { ?>
    <section class="card card-pad mt-6"><h2 class="text-lg font-semibold">What completing does</h2><p class="mt-1 text-sm text-ink-700"><?= e($what) ?></p>
      <?php if ($q['status'] === 'OPEN') { echo form_open($c, '/staff/admin/privacy/' . $q['request_id'] . '/review', 'mt-3') . '<button class="btn btn-secondary">Start review</button></form>'; } ?>
      <?= form_open($c, '/staff/admin/privacy/' . $q['request_id'] . '/complete', 'mt-4 space-y-3', 'data-confirm="Complete this ' . e(strtolower($q['type'])) . ' request now?"') ?>
        <?= textarea_field('Note to the person (optional)', 'note', '', 'maxlength="400"') ?>
        <button class="btn <?= $q['type'] === 'DELETION' ? 'btn-danger' : 'btn-primary' ?>">Complete request</button>
      </form>
      <?= form_open($c, '/staff/admin/privacy/' . $q['request_id'] . '/reject', 'mt-4 space-y-3') ?>
        <?= textarea_field('Reason for declining (shown to the person)', 'note', '', 'required maxlength="400"') ?>
        <button class="btn btn-secondary">Decline request</button>
      </form>
    </section>
  <?php } ?>
</div>
