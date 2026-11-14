<?php /** @var array $t */ ?>
<div class="container-page pb-4 pt-6 sm:pt-10">
  <nav aria-label="Breadcrumb" class="flex items-center text-sm text-ink-600"><a class="link inline-flex min-h-11 items-center" href="/rooms">Rooms</a> <span aria-hidden="true" class="mx-1">/</span> <?= e($t['name']) ?></nav>
  <div class="mt-4 flex flex-wrap items-end justify-between gap-4">
    <div>
      <h1 class="page-title"><?= e($t['name']) ?></h1>
      <p class="mt-2 text-lg text-ink-600"><?= e($t['tagline']) ?></p>
    </div>
    <p class="whitespace-nowrap"><span class="font-display text-3xl font-medium text-ink-950"><?= e(php_money($t['nightlyMinor'])) ?></span> <span class="text-ink-600">per night</span></p>
  </div>
  <div class="photo mt-6">
    <img src="/assets/img/<?= e($t['image']) ?>" alt="Photo of a <?= e($t['name']) ?> room" class="aspect-[4/3] w-full object-cover sm:aspect-[16/9] lg:aspect-[21/9]" width="800" height="520">
  </div>
  <div class="mt-8 grid gap-8 lg:mt-10 lg:grid-cols-3 lg:gap-10">
    <div class="lg:col-span-2">
      <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <?php foreach ([['Size', $t['sizeSqm'] . ' sqm'], ['Bed', $t['bedType']], ['Sleeps', (string) $t['maxGuests']], ['Rooms', $t['capacity'] . ' in the hotel']] as [$k, $v]) { ?>
          <div class="card flex flex-col p-4"><dt class="order-2 mt-1 text-sm text-ink-600"><?= e($k) ?></dt><dd class="font-display text-lg font-medium text-ink-950"><?= e($v) ?></dd></div>
        <?php } ?>
      </dl>
      <p class="text-pretty mt-8 text-lg text-ink-700"><?= e($t['description']) ?></p>
      <h2 class="mt-10 text-2xl font-medium">What is in the room</h2>
      <ul class="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2"><?php foreach ($t['amenities'] as $a) { ?><li class="flex items-center gap-3 text-ink-800"><span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-clay-50 text-clay-700"><?= icon('check', 'size-4') ?></span><?= e($a) ?></li><?php } ?></ul>
    </div>
    <aside class="lg:sticky lg:top-6 lg:self-start" aria-labelledby="book-h">
      <div class="card p-4 sm:p-5">
        <h2 id="book-h" class="px-1 text-lg font-semibold">Check your dates</h2>
        <p class="mt-1 px-1 text-sm text-ink-600">Taxes included. Free cancellation until 48 hours before arrival.</p>
        <div class="mt-4"><?php $compact = true; include __DIR__ . '/partials/search_form.php'; ?></div>
      </div>
    </aside>
  </div>
</div>
