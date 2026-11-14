<?php /** @var Portal\Web\Ctx $c @var array $requests @var array $a */
$typeLabel = ['ACCESS' => 'Copy of my data', 'CORRECTION' => 'Correct my details', 'DELETION' => 'Delete my data']; ?>
<div class="container-page max-w-3xl py-8">
  <h1 class="text-3xl font-bold">Your privacy</h1>
  <p class="mt-2 text-slate-700">We collect your name, email, phone number and booking details to host you and take payment. Card numbers never reach us: they are typed on the payment provider's page. We keep your guest profile for 24 months after your last stay. Payment records are kept as long as finance rules require. You can ask us to do any of the following. The privacy lead answers within 30 days.</p>

  <div class="mt-6 grid gap-6 md:grid-cols-3">
    <?= form_open($c, '/account/privacy', 'card card-pad space-y-3') ?>
      <input type="hidden" name="type" value="ACCESS"><h2 class="font-semibold">Get a copy of my data</h2>
      <p class="text-sm text-slate-600">A file with your account, bookings and the emails we sent you.</p>
      <?= textarea_field('Note (optional)', 'details', '', 'maxlength="600"') ?><button class="btn btn-secondary w-full">Request a copy</button>
    </form>
    <?= form_open($c, '/account/privacy', 'card card-pad space-y-3') ?>
      <input type="hidden" name="type" value="CORRECTION"><h2 class="font-semibold">Correct my details</h2>
      <?= field('Full name', 'fullName', 'text', $a['full_name'], 'required maxlength="120"') ?>
      <?= field('Email', 'email', 'email', $a['email'], 'required maxlength="160"') ?>
      <?= field('Phone', 'phone', 'tel', $a['phone'], 'required maxlength="40"') ?>
      <?= textarea_field('Note (optional)', 'details', '', 'maxlength="300"') ?><button class="btn btn-secondary w-full">Send correction</button>
    </form>
    <?= form_open($c, '/account/privacy', 'card card-pad space-y-3', 'data-confirm="Ask us to delete your account and personal data?"') ?>
      <input type="hidden" name="type" value="DELETION"><h2 class="font-semibold">Delete my data</h2>
      <p class="text-sm text-slate-600">We remove your name and contacts and close your account. Cancel or finish any upcoming stay first. Payment records stay for finance, without your name.</p>
      <?= textarea_field('Note (optional)', 'details', '', 'maxlength="600"') ?><button class="btn btn-danger w-full">Request deletion</button>
    </form>
  </div>

  <h2 class="mt-10 text-xl font-semibold">My requests</h2>
  <?php if ($requests) { ?>
    <ul class="mt-3 space-y-3">
      <?php foreach ($requests as $q) { ?>
        <li class="card card-pad">
          <p class="flex flex-wrap items-center gap-2 font-medium"><?= e($typeLabel[$q['type']]) ?> <?= badge($q['status']) ?> <span class="text-sm font-normal text-slate-600">filed <?= e(dt($q['created_at'])) ?>, answer due <?= e(dt($q['due_at'])) ?></span></p>
          <?php if ($q['resolution_note']) { ?><p class="mt-2 text-sm text-slate-700">Note from the privacy lead: <?= e($q['resolution_note']) ?></p><?php } ?>
          <?php if ($q['type'] === 'ACCESS' && $q['status'] === 'COMPLETED' && $q['has_result']) { ?><p class="mt-3"><a class="btn btn-primary btn-sm" href="/account/privacy/<?= e($q['request_id']) ?>/download"><?= icon('download', 'size-4') ?> Download my data</a></p><?php } ?>
        </li>
      <?php } ?>
    </ul>
  <?php } else { ?><p class="mt-3 text-slate-600">You have not made a request.</p><?php } ?>
</div>
