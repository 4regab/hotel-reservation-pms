<?php /** @var array $types */ ?>
<div class="container-page py-10">
  <h1 class="text-3xl font-bold">Rooms and suites</h1>
  <p class="mt-2 max-w-2xl text-slate-600">All rooms have free Wi-Fi, air conditioning, a rain shower and an in-room safe. Prices are per room per night in Philippine pesos, taxes included.</p>
  <div class="mt-8 space-y-8">
    <?php foreach ($types as $t) { ?>
      <article class="card overflow-hidden md:grid md:grid-cols-5">
        <img src="/assets/img/<?= e($t['image']) ?>" alt="Illustration of a <?= e($t['name']) ?> room" class="aspect-[3/2] w-full object-cover md:col-span-2 md:h-full" width="800" height="520" loading="lazy">
        <div class="p-5 sm:p-6 md:col-span-3">
          <div class="flex flex-wrap items-baseline justify-between gap-2"><h2 class="text-2xl font-bold"><a class="hover:underline" href="/rooms/<?= e($t['slug']) ?>"><?= e($t['name']) ?></a></h2><p><span class="text-2xl font-bold text-brand-900"><?= e(php_money($t['nightlyMinor'])) ?></span> <span class="text-sm text-slate-600">per night</span></p></div>
          <p class="mt-1 font-medium text-brand-800"><?= e($t['tagline']) ?></p>
          <p class="mt-3 text-slate-700"><?= e($t['description']) ?></p>
          <ul class="mt-4 flex flex-wrap gap-2 text-sm"><?php foreach (array_slice($t['amenities'], 0, 6) as $a) { ?><li class="badge badge-slate"><?= e($a) ?></li><?php } ?></ul>
          <div class="mt-5 flex flex-wrap gap-3"><a class="btn btn-primary" href="/search">Check dates</a><a class="btn btn-secondary" href="/rooms/<?= e($t['slug']) ?>">Room details</a></div>
        </div>
      </article>
    <?php } ?>
  </div>
</div>
