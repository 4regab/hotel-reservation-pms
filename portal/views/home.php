<?php /** @var Portal\Web\Ctx $c @var array $types */
$min = min(array_column($types, 'nightlyMinor'));
$faq = [
    ['Can I cancel my booking?', 'Yes. Cancel for free until 48 hours before your arrival (check-in is at 2:00 PM Manila time). After that, the first night is kept and the rest is refunded to your original payment method.'],
    ['How do I pay?', 'Pay online by card, GCash or Maya on our payment provider\'s secure page. We never see or store your card number. Your booking is confirmed the moment the provider confirms your payment.'],
    ['What does "held for 10 minutes" mean?', 'When you choose a room we set it aside for 10 minutes while you pay, so nobody can take it from under you. If the time runs out the room goes back on sale and you can simply search again.'],
    ['Can I pay when I arrive?', 'Ask the front desk. A manager can approve a pay-at-hotel guarantee for a booking, and we then confirm the room without an online payment.'],
    ['What time can I check in and out?', 'Check-in is from 2:00 PM and check-out is by 12:00 noon. If you arrive earlier, the front desk will do its best to have a room ready, but we cannot promise it.'],
    ['What do you do with my personal data?', 'We keep what we need to host you: your name, email, phone and booking details. You can see it, correct it or ask us to delete it from your account at any time.'],
];
?>
<!-- 1. Hero -->
<section class="relative isolate overflow-hidden bg-[#181729] text-white" aria-labelledby="hero-h">
  <img src="/assets/img/hero-manila-night.webp" alt="" class="absolute inset-x-0 bottom-0 -z-20 h-[clamp(14rem,27vw,25rem)] w-full object-cover object-[50%_60%] [mask-image:linear-gradient(to_bottom,transparent,black_60%)]" width="3008" height="784" fetchpriority="high">
  <div class="absolute inset-x-0 bottom-0 -z-10 h-32 bg-gradient-to-t from-[#181729]/60 to-transparent"></div>
  <div class="container-page flex min-h-[34rem] flex-col justify-center pb-40 pt-16 sm:min-h-[38rem] lg:min-h-[44rem] lg:pb-48">
    <p class="rise [--i:0] inline-flex w-fit items-center gap-2 rounded-full border border-white/25 bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-lantern-400"><?= icon('sparkle', 'size-4') ?> Boutique hotel, Metro Manila</p>
    <h1 id="hero-h" class="rise [--i:1] text-balance mt-5 max-w-3xl font-display text-5xl font-bold leading-[1.05] tracking-tight text-white sm:text-6xl lg:text-7xl">Wake up to the harbor.<span class="block text-brand-200">Be anywhere in minutes.</span></h1>
    <p class="rise [--i:2] text-pretty mt-6 max-w-xl text-lg text-brand-100 sm:text-xl">Forty-five calm rooms and suites with harbor and city views, from <strong class="font-semibold text-white"><?= e(php_money($min)) ?></strong> a night. Book direct and cancel free until 48 hours before you arrive.</p>
    <div class="rise [--i:3] mt-8 flex flex-wrap items-center gap-3">
      <a href="#find" class="btn btn-lg bg-lantern-400 text-slate-900 hover:bg-lantern-500">Check availability</a>
      <a href="/rooms" class="btn btn-lg border border-white/40 text-white hover:bg-white/10">Explore the rooms</a>
    </div>
    <ul class="rise [--i:4] mt-10 flex flex-wrap gap-x-6 gap-y-2 text-sm text-brand-100" aria-label="Booking promises">
      <li class="flex items-center gap-2"><?= icon('check', 'size-5 text-lantern-400') ?> Free cancellation</li>
      <li class="flex items-center gap-2"><?= icon('check', 'size-5 text-lantern-400') ?> Taxes included</li>
      <li class="flex items-center gap-2"><?= icon('check', 'size-5 text-lantern-400') ?> Secure card, GCash and Maya</li>
    </ul>
  </div>
</section>

<!-- 2. Search -->
<section id="find" class="container-page relative z-10 -mt-28 scroll-mt-6 lg:-mt-32" aria-labelledby="find-h">
  <div class="glass card card-pad shadow-2xl ring-1 ring-black/5 sm:p-8" data-reveal>
    <div class="flex flex-wrap items-baseline justify-between gap-2"><h2 id="find-h" class="text-xl font-bold sm:text-2xl">Find your room</h2><p class="text-sm text-slate-600">Live prices. Nothing is reserved until you choose.</p></div>
    <div class="mt-4"><?php include __DIR__ . '/partials/search_form.php'; ?></div>
  </div>
</section>

<!-- 3. Numbers -->
<section class="container-page mt-14" aria-label="HarborLight at a glance">
  <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-slate-200 bg-slate-200 lg:grid-cols-4" data-reveal>
    <?php foreach ([['45', 'rooms and suites'], ['48 h', 'free cancellation window'], ['10 min', 'room held while you pay'], ['2:00 PM', 'check-in, noon check-out']] as [$n, $l]) { ?>
      <div class="bg-white px-5 py-6 text-center sm:py-8"><dt class="sr-only"><?= e($l) ?></dt><dd class="tabular font-display text-3xl font-bold text-brand-900 sm:text-4xl"><?= e($n) ?><span class="mt-1 block font-sans text-sm font-normal text-slate-600"><?= e($l) ?></span></dd></div>
    <?php } ?>
  </dl>
</section>

<!-- 4. Rooms -->
<section class="container-page mt-20 sm:mt-28" aria-labelledby="rooms-h">
  <div class="max-w-2xl" data-reveal>
    <p class="text-sm font-semibold uppercase tracking-widest text-brand-800">Rooms and suites</p>
    <h2 id="rooms-h" class="text-balance mt-2 font-display text-4xl font-bold tracking-tight sm:text-5xl">Three ways to stay, one standard of calm.</h2>
    <p class="text-pretty mt-4 text-lg text-slate-600">Every room has free Wi-Fi, air conditioning, a rain shower and an in-room safe. Prices are per room per night, taxes included.</p>
  </div>
  <div class="mt-12 space-y-10 sm:space-y-16">
    <?php foreach ($types as $i => $t) { $flip = $i % 2 === 1; ?>
      <article class="group grid items-center gap-6 lg:grid-cols-12 lg:gap-12" data-reveal>
        <a href="/rooms/<?= e($t['slug']) ?>" class="relative block overflow-hidden rounded-3xl border border-slate-200 shadow-sm lg:col-span-7 <?= $flip ? 'lg:order-2' : '' ?>" tabindex="-1" aria-hidden="true">
          <img src="/assets/img/<?= e($t['image']) ?>" alt="" class="zoom-img aspect-[3/2] w-full object-cover" width="800" height="520" loading="lazy">
          <span class="absolute left-4 top-4 rounded-full bg-white/90 px-3 py-1 text-sm font-semibold text-brand-900 shadow-sm">From <?= e(php_money($t['nightlyMinor'])) ?></span>
        </a>
        <div class="lg:col-span-5 <?= $flip ? 'lg:order-1' : '' ?>">
          <p class="text-sm font-semibold uppercase tracking-widest text-brand-800"><?= e($t['sizeSqm']) ?> sqm &middot; <?= e($t['bedType']) ?> &middot; sleeps <?= e($t['maxGuests']) ?></p>
          <h3 class="mt-2 font-display text-3xl font-bold sm:text-4xl"><?= e($t['name']) ?></h3>
          <p class="mt-1 text-lg font-medium text-brand-800"><?= e($t['tagline']) ?></p>
          <p class="text-pretty mt-4 text-slate-700"><?= e($t['description']) ?></p>
          <ul class="mt-5 grid gap-2 sm:grid-cols-2"><?php foreach (array_slice($t['amenities'], 2, 6) as $a) { ?><li class="flex items-center gap-2 text-sm text-slate-800"><span class="text-emerald-700"><?= icon('check', 'size-4') ?></span><?= e($a) ?></li><?php } ?></ul>
          <div class="mt-7 flex flex-wrap items-center gap-3"><a href="/rooms/<?= e($t['slug']) ?>" class="btn btn-primary btn-lg">See the <?= e($t['name']) ?><span class="sr-only"> room</span></a><a href="#find" class="btn btn-ghost btn-lg">Check dates</a></div>
        </div>
      </article>
    <?php } ?>
  </div>
</section>

<!-- 5. How booking works -->
<section class="mt-24 bg-brand-900 py-16 text-white sm:mt-32 sm:py-24" aria-labelledby="how-h">
  <div class="container-page">
    <div class="max-w-2xl" data-reveal>
      <p class="text-sm font-semibold uppercase tracking-widest text-lantern-400">Booking, simply</p>
      <h2 id="how-h" class="text-balance mt-2 font-display text-4xl font-bold tracking-tight text-white sm:text-5xl">From search to room key in four steps.</h2>
    </div>
    <ol class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
      <?php foreach ([
          ['1', 'Pick your dates', 'See live prices for each room type. No account needed to look.'],
          ['2', 'We hold your room', 'Choose a room and it is set aside for 10 minutes while you pay.'],
          ['3', 'Pay securely', 'Card, GCash or Maya on the provider\'s page. Confirmed the moment it clears.'],
          ['4', 'Arrive and relax', 'Show your confirmation and a photo ID. Your room is ready from 2:00 PM.'],
      ] as $k => [$n, $h, $p]) { ?>
        <li class="relative rounded-2xl border border-white/15 bg-white/5 p-6 <?= ['[--i:0]', '[--i:1]', '[--i:2]', '[--i:3]'][$k] ?>" data-reveal>
          <span class="flex size-11 items-center justify-center rounded-full bg-lantern-400 font-display text-xl font-bold text-slate-900" aria-hidden="true"><?= e($n) ?></span>
          <h3 class="mt-4 text-lg font-semibold text-white"><span class="sr-only">Step <?= e($n) ?>: </span><?= e($h) ?></h3>
          <p class="mt-2 text-brand-100"><?= e($p) ?></p>
        </li>
      <?php } ?>
    </ol>
  </div>
</section>

<!-- 6. Included -->
<section class="container-page mt-20 sm:mt-28" aria-labelledby="inc-h">
  <div class="grid gap-10 lg:grid-cols-12 lg:items-start">
    <div class="lg:col-span-4" data-reveal>
      <p class="text-sm font-semibold uppercase tracking-widest text-brand-800">The details</p>
      <h2 id="inc-h" class="text-balance mt-2 font-display text-4xl font-bold tracking-tight">Small things, done properly.</h2>
      <p class="text-pretty mt-4 text-slate-600">We would rather get the basics exactly right than add a long list of extras.</p>
    </div>
    <ul class="grid gap-4 sm:grid-cols-2 lg:col-span-8">
      <?php foreach ([
          ['wifi', 'Fast free Wi-Fi', 'Included in every room and in the lobby.'],
          ['shower', 'Rain shower', 'Hot water that stays hot, in every bathroom.'],
          ['coffee', 'Breakfast included', 'For two in Deluxe rooms, for four in Suites.'],
          ['key', 'In-room safe', 'Big enough for a laptop and your passport.'],
          ['clock', 'Front desk, all day and night', 'Someone is always there to help with bags, taxis or directions.'],
          ['shield', 'Your data, your call', 'See, correct or delete what we hold about you from your account.'],
      ] as $k => [$i, $h, $p]) { ?>
        <li class="lift card flex gap-4 p-5 <?= ['[--i:0]', '[--i:1]', '[--i:2]', '[--i:3]', '[--i:4]', '[--i:5]'][$k] ?>" data-reveal><span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-100 text-brand-800"><?= icon($i, 'size-6') ?></span><div class="min-w-0"><h3 class="font-semibold"><?= e($h) ?></h3><p class="mt-1 text-sm text-slate-600"><?= e($p) ?></p></div></li>
      <?php } ?>
    </ul>
  </div>
</section>

<!-- 7. FAQ -->
<section class="container-page mt-20 max-w-4xl sm:mt-28" aria-labelledby="faq-h">
  <h2 id="faq-h" class="text-balance font-display text-4xl font-bold tracking-tight" data-reveal>Good to know before you book</h2>
  <div class="mt-8 divide-y divide-slate-200 rounded-2xl border border-slate-200 bg-white" data-reveal>
    <?php foreach ($faq as [$q, $a]) { ?>
      <details class="group/faq p-5 sm:px-7">
        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-4 text-left text-lg font-semibold text-slate-900"><?= e($q) ?><span class="shrink-0 text-brand-800 transition-transform duration-200 group-open/faq:rotate-45" aria-hidden="true"><?= icon('x', 'size-6 rotate-45') ?></span></summary>
        <p class="text-pretty mt-3 max-w-prose text-slate-700"><?= e($a) ?></p>
      </details>
    <?php } ?>
  </div>
</section>

<!-- 8. Closing call to action -->
<section class="container-page mt-20 sm:mt-28" aria-labelledby="cta-h">
  <div class="relative isolate overflow-hidden rounded-3xl bg-brand-900 px-6 py-14 text-center text-white sm:px-12 sm:py-20" data-reveal>
    <img src="/assets/img/hero-manila-night.webp" alt="" class="absolute inset-0 -z-10 size-full object-cover opacity-50" width="3008" height="784" loading="lazy">
    <div class="absolute inset-0 -z-10 bg-brand-950/70"></div>
    <h2 id="cta-h" class="text-balance mx-auto max-w-2xl font-display text-4xl font-bold tracking-tight text-white sm:text-5xl">Your room is waiting by the water.</h2>
    <p class="text-pretty mx-auto mt-4 max-w-xl text-lg text-brand-100">Book direct for the best rate, with free cancellation until 48 hours before arrival.</p>
    <div class="mt-8 flex flex-wrap justify-center gap-3"><a href="#find" class="btn btn-lg bg-lantern-400 text-slate-900 hover:bg-lantern-500">Check availability</a><a href="/register" class="btn btn-lg border border-white/40 text-white hover:bg-white/10">Create an account</a></div>
  </div>
</section>
