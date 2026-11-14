<?php /** @var Portal\Web\Ctx $c @var array $cards @var string $role @var string $name */
$roleLabel = ['reception' => 'Reception', 'housekeeping' => 'Housekeeping', 'maintenance' => 'Maintenance', 'cashier' => 'Cashier and finance', 'manager' => 'Hotel manager', 'it' => 'IT and privacy lead', 'steward' => 'Data steward'][$role] ?? ucfirst($role); ?>
<div class="container-page pb-4 pt-8 sm:pt-10">
  <p class="text-sm font-semibold uppercase tracking-wide text-ink-900"><?= e($roleLabel) ?></p>
  <h1 class="page-title">Good day, <?= e(explode(' ', $name)[0]) ?></h1>
  <p class="mt-1 text-ink-600"><?= e(d(today())) ?> &middot; Manila time</p>
  <ul class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
    <?php foreach ($cards as [$label, $value, $href, $cta]) { ?>
      <li class="card card-pad">
        <p class="text-sm font-medium text-ink-600"><?= e($label) ?></p>
        <p class="mt-1 font-display text-4xl font-medium tracking-tight text-ink-950"><?= e($value) ?></p>
        <a class="link mt-3 inline-block" href="<?= e($href) ?>"><?= e($cta) ?><span class="sr-only"> for <?= e($label) ?></span></a>
      </li>
    <?php } ?>
  </ul>
  <?php if (!$cards) { ?><p class="card card-pad mt-6">There is nothing on your dashboard yet. Use the menu above.</p><?php } ?>
  <p class="mt-8 text-sm text-ink-600">Your role only shows the tools you need. Every action you take is recorded in the audit log.</p>
</div>
