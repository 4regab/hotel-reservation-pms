<?php /** @var Portal\Web\Ctx $c @var array $guests @var string $q */ ?>
<div class="container-page max-w-5xl py-8">
  <h1 class="text-3xl font-bold">Guests</h1>
  <form method="get" action="/staff/guests" class="mt-4 flex flex-wrap items-end gap-3" role="search">
    <div class="min-w-0 flex-1 sm:max-w-md"><label class="label" for="q">Search guests</label><input class="input" id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Name, email or phone"></div>
    <button class="btn btn-secondary">Search</button>
  </form>
  <?php if ($guests) { ?>
    <div class="table-wrap mt-4"><table class="table"><caption class="sr-only">Guest records</caption>
      <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Phone</th><th scope="col">Account</th><th scope="col">Bookings</th><th scope="col">Last stay</th></tr></thead>
      <tbody><?php foreach ($guests as $g) { ?><tr><td class="font-medium"><?= e($g['fullName']) ?></td><td class="break-all"><?= e($g['email']) ?></td><td class="whitespace-nowrap"><?= e($g['phone']) ?></td><td><?= $g['hasAccount'] ? '<span class="badge badge-green">Yes</span>' : '<span class="badge badge-slate">No</span>' ?></td><td><?= e($g['bookings']) ?></td><td><?= e($g['lastStay'] ? d($g['lastStay']) : '-') ?></td></tr><?php } ?></tbody>
    </table></div>
  <?php } else { ?><div class="card card-pad mt-4 text-center">No guests found.</div><?php } ?>
  <?php if ($c->role() === 'steward') { ?><p class="mt-4"><a class="btn btn-primary" href="/staff/guests/duplicates">Review possible duplicates</a></p><?php } ?>
</div>
