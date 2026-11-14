<?php /** @var Portal\Web\Ctx $c @var array $types */
// The landing page sells the stay, not the price: prices appear only where a guest is choosing (rooms, search, booking).
$faq = [
    ['Can I cancel if my plans change?', 'Yes. Cancel free up to 48 hours before you arrive. Closer to your stay, we keep the first night and refund the rest to the way you paid.'],
    ['How can I pay?', 'By card, GCash or Maya, on our payment partner\'s secure page. Your card number never reaches us. We email your confirmation as soon as the payment goes through.'],
    ['Can I pay when I arrive instead?', 'Talk to the front desk. Our manager can approve paying at the hotel for your booking.'],
    ['What time can I check in and out?', 'Check-in is from 2:00 PM and check-out is by 12:00 noon. Arriving early? Tell the front desk and they will try to have your room ready, though we cannot promise it.'],
    ['What do you keep about me?', 'Only what we need to host you: your name, email, phone and booking details. You can see, correct or delete it from your account whenever you like.'],
];
?>
<!-- 1. Hero: a rounded photo frame with the header drawn on top of it -->
<section class="relative isolate mx-2 mt-2 overflow-hidden rounded-[2rem] bg-ink-950 text-white sm:mx-3 sm:mt-3" aria-labelledby="hero-h">
  <img src="/assets/img/hero-manila-night.webp" alt="" class="absolute inset-0 -z-20 size-full object-cover object-[50%_70%]" width="3008" height="784" fetchpriority="high">
  <div class="absolute inset-0 -z-10 bg-gradient-to-t from-ink-950 via-ink-950/75 to-ink-950/45"></div>
  <div class="container-page flex min-h-[38rem] flex-col justify-end pb-28 pt-32 sm:min-h-[42rem] sm:pb-32 lg:min-h-[46rem] lg:pb-40">
    <p class="rise [--i:0] eyebrow text-white/80 before:bg-clay-400">Boutique hotel in Metro Manila</p>
    <h1 id="hero-h" class="rise [--i:1] text-balance mt-5 max-w-5xl font-display text-4xl font-medium leading-[1.02] tracking-tight text-white sm:text-6xl lg:text-7xl">Rest well in the middle of Manila.<span class="block text-white/75">Quiet rooms, rain showers and a front desk that is open all night.</span></h1>
    <div class="rise [--i:2] mt-8 flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
      <p class="text-pretty max-w-md text-base text-white/90 sm:text-lg">Take a Standard room for a short city stay, a Deluxe with breakfast for two, or a Suite with its own living room. If your plans change, cancel free up to 48 hours before you arrive.</p>
      <a href="#find" class="btn btn-lg btn-primary btn-arrow shrink-0 self-start sm:order-last sm:self-end">Check your dates <span class="btn-chip"><?= icon('arrow', 'size-4') ?></span></a>
      <ul class="flex flex-wrap gap-2 text-sm sm:hidden lg:flex" aria-label="Why book with us">
        <?php foreach (['Free cancellation up to 48 hours', 'Card, GCash or Maya', 'Breakfast with Deluxe and Suites'] as $p) { ?>
          <li class="glass inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-white ring-1 ring-white/20"><?= icon('check', 'size-4 text-clay-300') ?> <?= e($p) ?></li>
        <?php } ?>
      </ul>
    </div>
  </div>
</section>

<!-- 2. Search: floats over the bottom edge of the hero -->
<section id="find" class="container-page relative z-10 -mt-20 scroll-mt-6 sm:-mt-24" aria-labelledby="find-h">
  <div class="search-bar">
    <div class="flex flex-wrap items-baseline justify-between gap-2 px-2 pb-3 pt-1"><h2 id="find-h" class="text-2xl font-medium">Plan your stay</h2><p class="text-sm text-ink-600">Pick your dates and we will show you the rooms that are free.</p></div>
    <?php include __DIR__ . '/partials/search_form.php'; ?>
  </div>
</section>

<!-- 3. About -->
<section class="container-page mt-24 sm:mt-32" aria-labelledby="about-h">
  <div class="grid gap-10 lg:grid-cols-12 lg:gap-16">
    <div class="lg:col-span-4"><p class="eyebrow">About MA Hotel</p></div>
    <div class="lg:col-span-8">
      <h2 id="about-h" class="text-balance font-display text-3xl font-medium leading-tight tracking-tight sm:text-4xl lg:text-5xl">Forty-five rooms, each one looked after properly. <span class="text-ink-500">We keep the hotel small so the details get our full attention.</span></h2>
      <p class="text-pretty mt-8 max-w-2xl text-lg text-ink-700">Every room has a rain shower, free Wi-Fi, air conditioning and a safe big enough for your laptop. Someone is at the front desk day and night, ready to hold your bags, call a taxi or point you to dinner.</p>
    </div>
  </div>
</section>

<!-- 4. Rooms -->
<section class="container-page mt-24 sm:mt-32" aria-labelledby="rooms-h">
  <div class="flex flex-wrap items-end justify-between gap-6">
    <div class="max-w-2xl">
      <h2 id="rooms-h" class="section-title">Pick the room that fits your trip.</h2>
    </div>
    <a href="/rooms" class="btn btn-secondary btn-arrow">Compare the rooms <span class="btn-chip"><?= icon('arrow', 'size-4') ?></span></a>
  </div>
  <ul class="mt-10 grid gap-6 md:grid-cols-3">
    <?php foreach ($types as $i => $t) { ?>
      <li class="group <?= ['[--i:0]', '[--i:1]', '[--i:2]'][$i % 3] ?>">
        <article>
          <a href="/rooms/<?= e($t['slug']) ?>" class="photo block" tabindex="-1" aria-hidden="true">
            <img src="/assets/img/<?= e($t['image']) ?>" alt="" class="zoom-img aspect-[4/3] w-full object-cover" width="800" height="600" loading="lazy">
            <span class="absolute left-3 top-3 rounded-full bg-white/95 px-3 py-1 text-xs font-semibold text-ink-900"><?= e($t['sizeSqm']) ?> sqm &middot; sleeps <?= e($t['maxGuests']) ?></span>
          </a>
          <div class="mt-4 px-1">
            <h3 class="font-display text-xl font-medium tracking-tight"><a href="/rooms/<?= e($t['slug']) ?>" class="hover:underline"><?= e($t['name']) ?><span class="sr-only"> room</span></a></h3>
            <p class="mt-1 text-sm text-ink-600"><?= e($t['tagline']) ?></p>
          </div>
          <p class="mt-2 flex items-center gap-1.5 px-1 text-sm text-ink-700"><?= icon('bed', 'size-4') ?> <?= e($t['bedType']) ?></p>
          <ul class="mt-3 flex flex-wrap gap-1.5 px-1"><?php foreach (array_slice($t['amenities'], 2, 3) as $a) { ?><li class="chip"><?= e($a) ?></li><?php } ?></ul>
        </article>
      </li>
    <?php } ?>
  </ul>
</section>

<!-- 5. Booking direct -->
<section class="mx-2 mt-24 sm:mx-3 sm:mt-32" aria-labelledby="how-h">
  <div class="panel-dark py-16 sm:py-24">
    <div class="container-page">
      <div class="grid gap-6 lg:grid-cols-12">
        <div class="lg:col-span-5"><h2 id="how-h" class="section-title text-white">Book with us directly, and keep it simple.</h2></div>
        <p class="text-pretty text-ink-300 sm:text-lg lg:col-span-5 lg:col-start-8 lg:self-end">You deal with the hotel itself, from the first click to the morning you check out.</p>
      </div>
      <ul class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <?php foreach ([
            ['Free cancellation', 'Plans change. Cancel free up to 48 hours before you arrive.'],
            ['Pay your way', 'Card, GCash or Maya, on our payment partner\'s secure page.'],
            ['Confirmation by email', 'Your booking code lands in your inbox and stays in your account, ready to print.'],
            ['Settle in from 2:00 PM', 'Check-in is from 2:00 PM and check-out is by noon. Bring a photo ID.'],
        ] as $k => [$h, $p]) { ?>
          <li class="rounded-3xl bg-white/[0.04] p-6 ring-1 ring-white/10 <?= ['[--i:0]', '[--i:1]', '[--i:2]', '[--i:3]'][$k] ?>">
            <span class="flex size-10 items-center justify-center rounded-full bg-clay-500 text-ink-950" aria-hidden="true"><?= icon(['calendar', 'shield', 'check', 'key'][$k], 'size-5') ?></span>
            <h3 class="mt-6 text-lg font-semibold text-white"><?= e($h) ?></h3>
            <p class="mt-2 text-sm text-ink-300"><?= e($p) ?></p>
          </li>
        <?php } ?>
      </ul>
    </div>
  </div>
</section>

<!-- 6. Included -->
<section class="container-page mt-24 sm:mt-32" aria-labelledby="inc-h">
  <div class="grid gap-10 lg:grid-cols-12 lg:items-start">
    <div class="lg:col-span-4">
      <h2 id="inc-h" class="section-title">Small things, done properly.</h2>
      <p class="text-pretty mt-4 text-ink-600">We would rather get the everyday things right than fill a page with extras.</p>
    </div>
    <ul class="grid gap-4 sm:grid-cols-2 lg:col-span-8">
      <?php foreach ([
          ['wifi', 'Free Wi-Fi', 'In every room and in the lobby.'],
          ['shower', 'A proper rain shower', 'In every bathroom, with hot water that stays hot.'],
          ['coffee', 'Breakfast included', 'For two with a Deluxe room, for four with a Suite.'],
          ['key', 'In-room safe', 'Room for your laptop and your passport.'],
          ['clock', 'Front desk, day and night', 'Late flight or early start, someone is there to let you in.'],
          ['shield', 'Your details stay yours', 'See, correct or delete what we keep about you, from your account.'],
      ] as $k => [$i, $h, $p]) { ?>
        <li class="lift card flex gap-4 p-5 <?= ['[--i:0]', '[--i:1]', '[--i:2]', '[--i:3]', '[--i:4]', '[--i:5]'][$k] ?>"><span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-clay-50 text-clay-700"><?= icon($i, 'size-5') ?></span><div class="min-w-0"><h3 class="font-semibold"><?= e($h) ?></h3><p class="mt-1 text-sm text-ink-600"><?= e($p) ?></p></div></li>
      <?php } ?>
    </ul>
  </div>
</section>

<!-- 7. FAQ -->
<section class="container-page mt-24 sm:mt-32" aria-labelledby="faq-h">
  <div class="grid gap-10 lg:grid-cols-12">
    <div class="lg:col-span-4"><h2 id="faq-h" class="section-title">Questions guests ask before they book.</h2></div>
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
    <h2 id="cta-h" class="text-balance mx-auto max-w-2xl font-display text-4xl font-medium tracking-tight text-white sm:text-5xl">Your next night in Manila starts here.</h2>
    <p class="text-pretty mx-auto mt-4 max-w-xl text-lg text-white/85">Choose your dates, pick your room, and cancel free up to 48 hours before you arrive if plans change.</p>
    <div class="mt-8 flex flex-wrap justify-center gap-3"><a href="#find" class="btn btn-lg btn-primary btn-arrow">Check your dates <span class="btn-chip"><?= icon('arrow', 'size-4') ?></span></a><a href="/register" class="btn btn-lg btn-outline-light">Create an account</a></div>
  </div>
</section>
