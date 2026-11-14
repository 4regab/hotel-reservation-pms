<?php /** @var Portal\Web\Ctx $c @var array $types */
$min = min(array_column($types, 'nightlyMinor'));
$faq = [
    ['Can I cancel my booking?', 'Yes. Cancel for free until 48 hours before your arrival (check-in is at 2:00 PM Manila time). After that, the first night is kept and the rest is refunded to your original payment method.'],
    ['How do I pay?', 'Pay online by card, GCash or Maya on our payment provider\'s secure page. We never see or store your card number. Your booking is confirmed the moment the provider confirms your payment.'],
    ['What does "held for 10 minutes" mean?', 'When you choose a room we set it aside for 10 minutes while you pay, so nobody can take it from under you. If the time runs out the room goes back on sale and you can search again.'],
    ['Can I pay when I arrive?', 'Ask the front desk. A manager can approve a pay-at-hotel guarantee for a booking, and we then confirm the room without an online payment.'],
    ['What time can I check in and out?', 'Check-in is from 2:00 PM and check-out is by 12:00 noon. If you arrive earlier, the front desk will do its best to have a room ready, but we cannot promise it.'],
    ['What do you do with my personal data?', 'We keep what we need to host you: your name, email, phone and booking details. You can see it, correct it or ask us to delete it from your account at any time.'],
];
?>
<!-- 1. Hero: a rounded photo frame with the header drawn on top of it -->
<section class="relative isolate mx-2 mt-2 overflow-hidden rounded-[2rem] bg-ink-950 text-white sm:mx-3 sm:mt-3" aria-labelledby="hero-h">
  <img src="/assets/img/hero-manila-night.webp" alt="" class="absolute inset-0 -z-20 size-full object-cover object-[50%_70%]" width="3008" height="784" fetchpriority="high">
  <div class="absolute inset-0 -z-10 bg-gradient-to-t from-ink-950 via-ink-950/75 to-ink-950/45"></div>
  <div class="container-page flex min-h-[38rem] flex-col justify-end pb-28 pt-32 sm:min-h-[42rem] sm:pb-32 lg:min-h-[46rem] lg:pb-40">
    <p class="rise [--i:0] eyebrow text-white/80 before:bg-clay-400">Boutique hotel in Metro Manila</p>
    <h1 id="hero-h" class="rise [--i:1] text-balance mt-5 max-w-5xl font-display text-4xl font-medium leading-[1.02] tracking-tight text-white sm:text-6xl lg:text-7xl">A 45-room hotel in Metro Manila.<span class="block text-white/75">Book direct from <?= e(php_money($min)) ?> a night.</span></h1>
    <div class="rise [--i:2] mt-8 flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
      <p class="text-pretty max-w-md text-base text-white/90 sm:text-lg">Twenty Standard rooms, fifteen Deluxe rooms and ten Suites, from <strong class="font-semibold text-white"><?= e(php_money($min)) ?></strong> a night with taxes included. Cancel free until 48 hours before you arrive.</p>
      <a href="#find" class="btn btn-lg btn-primary btn-arrow shrink-0 self-start sm:order-last sm:self-end">Check availability <span class="btn-chip"><?= icon('arrow', 'size-4') ?></span></a>
      <ul class="flex flex-wrap gap-2 text-sm sm:hidden lg:flex" aria-label="Booking promises">
        <?php foreach (['Free cancellation', 'Taxes included', 'Card, GCash and Maya'] as $p) { ?>
          <li class="glass inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-white ring-1 ring-white/20"><?= icon('check', 'size-4 text-clay-300') ?> <?= e($p) ?></li>
        <?php } ?>
      </ul>
    </div>
  </div>
</section>

<!-- 2. Search: floats over the bottom edge of the hero -->
<section id="find" class="container-page relative z-10 -mt-20 scroll-mt-6 sm:-mt-24" aria-labelledby="find-h">
  <div class="search-bar">
    <div class="flex flex-wrap items-baseline justify-between gap-2 px-2 pb-3 pt-1"><h2 id="find-h" class="text-2xl font-medium">Find your room</h2><p class="text-sm text-ink-600">Live prices. Nothing is reserved until you choose.</p></div>
    <?php include __DIR__ . '/partials/search_form.php'; ?>
  </div>
</section>

<!-- 3. About and numbers -->
<section class="container-page mt-24 sm:mt-32" aria-labelledby="about-h">
  <div class="grid gap-10 lg:grid-cols-12 lg:gap-16">
    <div class="lg:col-span-4"><p class="eyebrow">About MA Hotel</p></div>
    <div class="lg:col-span-8">
      <h2 id="about-h" class="text-balance font-display text-3xl font-medium leading-tight tracking-tight sm:text-4xl lg:text-5xl">A small hotel that gets the basics right. <span class="text-ink-500">Rain showers, a safe for your laptop and a front desk that never closes.</span></h2>
      <dl class="mt-12 grid grid-cols-2 gap-x-6 gap-y-8 border-t border-ink-200 pt-8 sm:grid-cols-4">
        <?php foreach ([['45', 'rooms and suites'], ['48 h', 'free cancellation'], ['10 min', 'room held while you pay'], ['2:00 PM', 'check-in, noon check-out']] as [$n, $l]) { ?>
          <div class="flex flex-col"><dt class="stat-label order-2"><?= e($l) ?></dt><dd class="stat-value -order-1"><?= e($n) ?></dd></div>
        <?php } ?>
      </dl>
    </div>
  </div>
</section>

<!-- 4. Rooms -->
<section class="container-page mt-24 sm:mt-32" aria-labelledby="rooms-h">
  <div class="flex flex-wrap items-end justify-between gap-6">
    <div class="max-w-2xl">
      <h2 id="rooms-h" class="section-title">Standard, Deluxe or Suite.<br class="hidden sm:block"> Every room has Wi-Fi and a rain shower.</h2>
    </div>
    <a href="/rooms" class="btn btn-secondary btn-arrow">Compare all rooms <span class="btn-chip"><?= icon('arrow', 'size-4') ?></span></a>
  </div>
  <ul class="mt-10 grid gap-6 md:grid-cols-3">
    <?php foreach ($types as $i => $t) { ?>
      <li class="group <?= ['[--i:0]', '[--i:1]', '[--i:2]'][$i % 3] ?>">
        <article>
          <a href="/rooms/<?= e($t['slug']) ?>" class="photo block" tabindex="-1" aria-hidden="true">
            <img src="/assets/img/<?= e($t['image']) ?>" alt="" class="zoom-img aspect-[4/3] w-full object-cover" width="800" height="600" loading="lazy">
            <span class="absolute left-3 top-3 rounded-full bg-white/95 px-3 py-1 text-xs font-semibold text-ink-900"><?= e($t['sizeSqm']) ?> sqm &middot; sleeps <?= e($t['maxGuests']) ?></span>
          </a>
          <div class="mt-4 flex items-baseline justify-between gap-3 px-1">
            <h3 class="font-display text-xl font-medium tracking-tight"><a href="/rooms/<?= e($t['slug']) ?>" class="hover:underline"><?= e($t['name']) ?><span class="sr-only"> room</span></a></h3>
            <p class="whitespace-nowrap text-right"><span class="text-xs text-ink-600">from</span> <span class="font-semibold text-ink-950"><?= e(php_money($t['nightlyMinor'])) ?></span></p>
          </div>
          <p class="mt-1 flex items-center gap-1.5 px-1 text-sm text-ink-600"><?= icon('bed', 'size-4') ?> <?= e($t['bedType']) ?> &middot; <?= e($t['tagline']) ?></p>
          <ul class="mt-3 flex flex-wrap gap-1.5 px-1"><?php foreach (array_slice($t['amenities'], 2, 3) as $a) { ?><li class="chip"><?= e($a) ?></li><?php } ?></ul>
        </article>
      </li>
    <?php } ?>
  </ul>
</section>

<!-- 5. How booking works -->
<section class="mx-2 mt-24 sm:mx-3 sm:mt-32" aria-labelledby="how-h">
  <div class="panel-dark py-16 sm:py-24">
    <div class="container-page">
      <div class="grid gap-6 lg:grid-cols-12">
        <div class="lg:col-span-5"><h2 id="how-h" class="section-title text-white">From search to room key in four steps.</h2></div>
        <p class="text-pretty text-ink-300 sm:text-lg lg:col-span-5 lg:col-start-8 lg:self-end">No account needed to look. Prices include taxes, and the room you pick is set aside while you pay.</p>
      </div>
      <ol class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <?php foreach ([
            ['Pick your dates', 'See live prices for each room type.'],
            ['We hold your room', 'Choose a room and it is set aside for 10 minutes while you pay.'],
            ['Pay securely', 'Card, GCash or Maya on the provider\'s page. Confirmed the moment it clears.'],
            ['Arrive and relax', 'Show your confirmation and a photo ID. Your room is ready from 2:00 PM.'],
        ] as $k => [$h, $p]) { ?>
          <li class="rounded-3xl bg-white/[0.04] p-6 ring-1 ring-white/10 <?= ['[--i:0]', '[--i:1]', '[--i:2]', '[--i:3]'][$k] ?>">
            <span class="font-display text-sm font-medium text-clay-300" aria-hidden="true">0<?= $k + 1 ?></span>
            <h3 class="mt-8 text-lg font-semibold text-white"><span class="sr-only">Step <?= $k + 1 ?>: </span><?= e($h) ?></h3>
            <p class="mt-2 text-sm text-ink-300"><?= e($p) ?></p>
          </li>
        <?php } ?>
      </ol>
    </div>
  </div>
</section>

<!-- 6. Included -->
<section class="container-page mt-24 sm:mt-32" aria-labelledby="inc-h">
  <div class="grid gap-10 lg:grid-cols-12 lg:items-start">
    <div class="lg:col-span-4">
      <h2 id="inc-h" class="section-title">Small things, done properly.</h2>
      <p class="text-pretty mt-4 text-ink-600">We would rather get the basics exactly right than add a long list of extras.</p>
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
        <li class="lift card flex gap-4 p-5 <?= ['[--i:0]', '[--i:1]', '[--i:2]', '[--i:3]', '[--i:4]', '[--i:5]'][$k] ?>"><span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-clay-50 text-clay-700"><?= icon($i, 'size-5') ?></span><div class="min-w-0"><h3 class="font-semibold"><?= e($h) ?></h3><p class="mt-1 text-sm text-ink-600"><?= e($p) ?></p></div></li>
      <?php } ?>
    </ul>
  </div>
</section>

<!-- 7. FAQ -->
<section class="container-page mt-24 sm:mt-32" aria-labelledby="faq-h">
  <div class="grid gap-10 lg:grid-cols-12">
    <div class="lg:col-span-4"><h2 id="faq-h" class="section-title">Good to know before you book.</h2></div>
    <div class="space-y-3 lg:col-span-8">
      <?php foreach ($faq as [$q, $a]) { ?>
        <details class="group/faq card px-5 py-1 sm:px-6">
          <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-4 text-left font-semibold text-ink-950"><?= e($q) ?><span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-ink-100 text-ink-800 transition-transform duration-200 group-open/faq:rotate-45" aria-hidden="true"><?= icon('x', 'size-4 rotate-45') ?></span></summary>
          <p class="text-pretty max-w-prose pb-5 text-ink-600"><?= e($a) ?></p>
        </details>
      <?php } ?>
    </div>
  </div>
</section>

<!-- 8. Closing call to action -->
<section class="container-page mt-24 sm:mt-32" aria-labelledby="cta-h">
  <div class="relative isolate overflow-hidden rounded-[2rem] bg-ink-950 px-6 py-16 text-center text-white sm:px-12 sm:py-24">
    <img src="/assets/img/room-suite.webp" alt="" class="absolute inset-0 -z-20 size-full object-cover" width="800" height="520" loading="lazy">
    <div class="absolute inset-0 -z-10 bg-ink-950/70"></div>
    <h2 id="cta-h" class="text-balance mx-auto max-w-2xl font-display text-4xl font-medium tracking-tight text-white sm:text-5xl">Your room is ready when you are.</h2>
    <p class="text-pretty mx-auto mt-4 max-w-xl text-lg text-white/80">Book direct for the best rate, with free cancellation until 48 hours before arrival.</p>
    <div class="mt-8 flex flex-wrap justify-center gap-3"><a href="#find" class="btn btn-lg btn-primary btn-arrow">Check availability <span class="btn-chip"><?= icon('arrow', 'size-4') ?></span></a><a href="/register" class="btn btn-lg btn-outline-light">Create an account</a></div>
  </div>
</section>
