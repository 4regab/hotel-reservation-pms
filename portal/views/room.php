<?php /** @var array $t */ ?>
<div class="container-page py-10">
  <nav aria-label="Breadcrumb" class="text-sm text-slate-600"><a class="link" href="/rooms">Rooms</a> <span aria-hidden="true">/</span> <?= e($t['name']) ?></nav>
  <div class="mt-4 grid gap-8 lg:grid-cols-3">
    <div class="lg:col-span-2">
      <img src="/assets/img/<?= e($t['image']) ?>" alt="Illustration of a <?= e($t['name']) ?> room" class="w-full rounded-xl border border-slate-200 object-cover" width="800" height="520">
      <h1 class="mt-6 text-3xl font-bold"><?= e($t['name']) ?></h1>
      <p class="mt-1 text-lg font-medium text-brand-800"><?= e($t['tagline']) ?></p>
      <p class="mt-4 text-slate-700"><?= e($t['description']) ?></p>
      <dl class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="card card-pad"><dt class="text-xs font-semibold uppercase text-slate-600">Size</dt><dd class="mt-1 font-semibold"><?= e($t['sizeSqm']) ?> sqm</dd></div>
        <div class="card card-pad"><dt class="text-xs font-semibold uppercase text-slate-600">Bed</dt><dd class="mt-1 font-semibold"><?= e($t['bedType']) ?></dd></div>
        <div class="card card-pad"><dt class="text-xs font-semibold uppercase text-slate-600">Sleeps</dt><dd class="mt-1 font-semibold"><?= e($t['maxGuests']) ?></dd></div>
        <div class="card card-pad"><dt class="text-xs font-semibold uppercase text-slate-600">Rooms</dt><dd class="mt-1 font-semibold"><?= e($t['capacity']) ?> in the hotel</dd></div>
      </dl>
      <h2 class="mt-8 text-xl font-semibold">What is in the room</h2>
      <ul class="mt-3 grid gap-2 sm:grid-cols-2"><?php foreach ($t['amenities'] as $a) { ?><li class="flex items-center gap-2 text-slate-800"><span class="text-emerald-700"><?= icon('check', 'size-5') ?></span><?= e($a) ?></li><?php } ?></ul>
    </div>
    <aside class="lg:sticky lg:top-4 lg:self-start">
      <div class="card card-pad">
        <p><span class="text-3xl font-bold text-brand-900"><?= e(php_money($t['nightlyMinor'])) ?></span> <span class="text-slate-600">per night</span></p>
        <p class="mt-1 text-sm text-slate-600">Taxes included. Free cancellation until 48 hours before arrival.</p>
        <div class="mt-4"><?php include __DIR__ . '/partials/search_form.php'; ?></div>
      </div>
    </aside>
  </div>
</div>
