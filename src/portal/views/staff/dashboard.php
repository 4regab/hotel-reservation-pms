<?php /** @var Portal\Web\Ctx $c @var array $cards @var string $role @var string $name */
$roleLabel = ['reception' => 'Reception', 'housekeeping' => 'Housekeeping', 'maintenance' => 'Maintenance', 'cashier' => 'Cashier and finance', 'manager' => 'Hotel manager', 'it' => 'IT and privacy lead', 'steward' => 'Data steward'][$role] ?? ucfirst($role); ?>
<div class="container-page pb-4 pt-8 sm:pt-10">
  <h1 class="page-title">Good day, <?= e(explode(' ', $name)[0]) ?></h1>
  <p class="mt-1 text-sm text-ink-600"><?= e($roleLabel) ?> &middot; <?= e(d(today())) ?>, Manila time</p>
  <ul class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <?php foreach ($cards as [$label, $value, $href, $cta]) { ?>
      <li class="card card-pad flex flex-col">
        <p class="kpi-label"><?= e($label) ?></p>
        <p class="kpi-value text-3xl"><?= e($value) ?></p>
        <a class="link mt-4 inline-flex min-h-11 items-center self-start text-sm xl:min-h-0" href="<?= e($href) ?>"><?= e($cta) ?><span class="sr-only"> for <?= e($label) ?></span></a>
      </li>
    <?php } ?>
  </ul>
  <?php if (!$cards) { ?><p class="card card-pad mt-6">There is nothing on your dashboard yet. Use the menu above.</p><?php } ?>
</div>
