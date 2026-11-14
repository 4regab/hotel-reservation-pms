<?php /** @var Portal\Web\Ctx $c @var array $types */ ?>
<section class="relative isolate overflow-hidden bg-brand-950 text-white">
  <img src="/assets/img/hero-harbor.svg" alt="" class="absolute inset-0 -z-10 size-full object-cover" width="1600" height="700" fetchpriority="high">
  <div class="absolute inset-0 -z-10 bg-gradient-to-r from-brand-950/85 via-brand-950/55 to-brand-950/10"></div>
  <div class="container-page pb-28 pt-16 sm:pt-24 lg:pb-36 lg:pt-32">
    <p class="text-sm font-semibold uppercase tracking-widest text-lantern-400">Metro Manila boutique hotel</p>
    <h1 class="mt-3 max-w-2xl font-display text-4xl font-bold leading-tight text-white sm:text-5xl lg:text-6xl">Stay by the water, minutes from everything.</h1>
    <p class="mt-4 max-w-xl text-lg text-brand-100">Forty-five calm rooms and suites with harbor and city views. Book direct for the best rate and free cancellation up to 48 hours before arrival.</p>
  </div>
</section>

<section class="container-page relative z-10 -mt-20 lg:-mt-24" aria-labelledby="find">
  <div class="card card-pad shadow-lg">
    <h2 id="find" class="mb-3 text-lg font-semibold">Find your room</h2>
    <?php include __DIR__ . '/partials/search_form.php'; ?>
  </div>
</section>

<section class="container-page mt-16" aria-labelledby="rooms-h">
  <div class="flex flex-wrap items-end justify-between gap-2">
    <div><h2 id="rooms-h" class="text-2xl font-bold sm:text-3xl">Rooms and suites</h2><p class="mt-1 text-slate-600">Three ways to stay. Prices are per room, per night, taxes included.</p></div>
    <a class="link" href="/rooms">See all details</a>
  </div>
  <div class="mt-6 grid gap-6 md:grid-cols-3">
    <?php foreach ($types as $t) { ?>
      <article class="card flex flex-col overflow-hidden">
        <img src="/assets/img/<?= e($t['image']) ?>" alt="" class="aspect-[3/2] w-full object-cover" width="800" height="520" loading="lazy">
        <div class="flex flex-1 flex-col p-5">
          <h3 class="text-xl font-semibold"><?= e($t['name']) ?></h3>
          <p class="mt-1 text-sm text-slate-600"><?= e($t['tagline']) ?></p>
          <p class="mt-3 text-sm text-slate-700"><?= e($t['sizeSqm']) ?> sqm &middot; <?= e($t['bedType']) ?> &middot; up to <?= e($t['maxGuests']) ?> guests</p>
          <p class="mt-auto pt-4"><span class="text-2xl font-bold text-brand-900"><?= e(php_money($t['nightlyMinor'])) ?></span> <span class="text-sm text-slate-600">per night</span></p>
          <a href="/rooms/<?= e($t['slug']) ?>" class="btn btn-secondary mt-3">View <?= e($t['name']) ?><span class="sr-only"> room details</span></a>
        </div>
      </article>
    <?php } ?>
  </div>
</section>

<section class="container-page mt-16" aria-labelledby="why">
  <h2 id="why" class="text-2xl font-bold sm:text-3xl">Why book direct</h2>
  <ul class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <?php foreach ([
        ['clock', 'Free cancellation', 'Cancel up to 48 hours before arrival and pay nothing.'],
        ['shield', 'Secure payment', 'Pay by card or e-wallet on a hosted page. We never see your card number.'],
        ['calendar', 'Held for 10 minutes', 'Pick a room and we hold it while you pay. No surprises at the last step.'],
        ['user', 'One account', 'See, change or print your bookings and receipts any time.'],
    ] as [$i, $h, $p]) { ?>
      <li class="card card-pad"><span class="inline-flex size-10 items-center justify-center rounded-lg bg-brand-100 text-brand-800"><?= icon($i) ?></span><h3 class="mt-3 font-semibold"><?= e($h) ?></h3><p class="mt-1 text-sm text-slate-600"><?= e($p) ?></p></li>
    <?php } ?>
  </ul>
</section>

<section class="container-page mt-16">
  <div class="card card-pad grid gap-6 bg-brand-900 text-white sm:grid-cols-3">
    <div><h2 class="text-xl font-bold text-white">Arrival and departure</h2><p class="mt-2 text-brand-100">Check-in from 2:00 PM. Check-out by 12:00 noon. Early arrival is possible when a room is ready, ask at the front desk.</p></div>
    <div><h2 class="text-xl font-bold text-white">Everything included</h2><p class="mt-2 text-brand-100">Free Wi-Fi, air conditioning and a rain shower in every room. Deluxe rooms add breakfast for two, suites for four.</p></div>
    <div><h2 class="text-xl font-bold text-white">Need a hand?</h2><p class="mt-2 text-brand-100">The front desk is open around the clock. Sign in to see your booking, or create an account in a minute.</p><a href="/register" class="btn mt-3 bg-lantern-400 text-slate-900 hover:bg-lantern-500">Create account</a></div>
  </div>
</section>
