<?php /** @var array $types */ ?>
<div class="container-page pb-4 pt-8 sm:pt-12">
  <div class="page-head">
    <div>
      <p class="eyebrow">Rooms and suites</p>
      <h1 class="page-title mt-3">Rooms and suites</h1>
      <p class="page-lead">All rooms have free Wi-Fi, air conditioning, a rain shower and an in-room safe. Prices are per room per night in Philippine pesos, taxes included.</p>
    </div>
    <a class="btn btn-primary btn-arrow" href="/search">Check dates <span class="btn-chip"><?= icon('arrow', 'size-4') ?></span></a>
  </div>
  <div class="mt-10 space-y-6 sm:mt-14">
    <?php foreach ($types as $t) { ?>
      <article class="group card overflow-hidden p-2 md:grid md:grid-cols-12 md:gap-2">
        <div class="photo md:col-span-5 lg:col-span-6">
          <img src="/assets/img/<?= e($t['image']) ?>" alt="Photo of a <?= e($t['name']) ?> room" class="zoom-img aspect-[3/2] w-full object-cover md:aspect-auto md:h-full" width="800" height="520" loading="lazy">
        </div>
        <div class="flex flex-col p-4 sm:p-6 md:col-span-7 lg:col-span-6 lg:p-8">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-display text-3xl font-medium tracking-tight sm:text-4xl"><a class="hover:underline" href="/rooms/<?= e($t['slug']) ?>"><?= e($t['name']) ?></a></h2>
            <p class="whitespace-nowrap"><span class="text-2xl font-semibold text-ink-950"><?= e(php_money($t['nightlyMinor'])) ?></span> <span class="text-sm text-ink-600">per night</span></p>
          </div>
          <p class="mt-1 font-medium text-ink-600"><?= e($t['tagline']) ?></p>
          <ul class="mt-4 flex flex-wrap gap-x-5 gap-y-1 text-sm text-ink-700">
            <li class="flex items-center gap-1.5"><?= icon('expand', 'size-4') ?> <?= e($t['sizeSqm']) ?> sqm</li>
            <li class="flex items-center gap-1.5"><?= icon('bed', 'size-4') ?> <?= e($t['bedType']) ?></li>
            <li class="flex items-center gap-1.5"><?= icon('users', 'size-4') ?> Sleeps <?= e($t['maxGuests']) ?></li>
          </ul>
          <p class="text-pretty mt-4 text-ink-700"><?= e($t['description']) ?></p>
          <ul class="mt-5 flex flex-wrap gap-1.5"><?php foreach (array_slice($t['amenities'], 0, 6) as $a) { ?><li class="chip"><?= e($a) ?></li><?php } ?></ul>
          <div class="mt-auto flex flex-wrap gap-3 pt-6"><a class="btn btn-primary" href="/search">Check dates</a><a class="btn btn-secondary" href="/rooms/<?= e($t['slug']) ?>">Room details<span class="sr-only"> for <?= e($t['name']) ?></span></a></div>
        </div>
      </article>
    <?php } ?>
  </div>
</div>
