<?php /** @var Portal\Web\Ctx $c @var array $types */
// The landing page sells the stay, not the price: prices appear only where a guest is choosing (rooms, search, booking).
$form = ['checkIn' => plus_days(today(), 1), 'checkOut' => plus_days(today(), 3)];
$faq = [
    ['Can I cancel if my plans change?', 'Yes. Cancel free up to 48 hours before you arrive. Closer to your stay, we keep the first night and refund the rest to the way you paid.'],
    ['Is breakfast included?', 'Yes with a Deluxe room (breakfast for two) and a Suite (breakfast for four). Standard rooms are room only.'],
    ['How can I pay?', 'By card, GCash or Maya, on our payment partner\'s secure page. Your card number never reaches us. We email your confirmation as soon as the payment goes through.'],
    ['Can I pay when I arrive instead?', 'Talk to the front desk. Our manager can approve paying at the hotel for your booking.'],
    ['What time can I check in and out?', 'Check-in is from 2:00 PM and check-out is by 12:00 noon. Arriving early? Tell the front desk and they will try to have your room ready, though we cannot promise it.'],
];
$chapters = [
    ['pasig-river.webp', 1600, 1066, 'Book in a few minutes', 'Pick your dates, choose a room and see the full price, VAT included, before you pay. We hold the room for you for ten minutes while you finish.'],
    ['front-desk.webp', 1400, 788, 'Pay your way, then check in from 2:00 PM', 'Pay by card, GCash or Maya on our payment partner\'s secure page. At the front desk, show a photo ID and your booking code.'],
    ['room-bed-detail.webp', 1400, 1050, 'Settle into your room', 'Air conditioning, a rain shower, free Wi-Fi and a safe big enough for a laptop, in every room. The front desk is open all night if you need anything.'],
    ['breakfast-table.webp', 1200, 900, 'Breakfast, then check out by noon', 'Deluxe rooms come with breakfast for two and Suites with breakfast for four. Check-out is by 12:00 noon, and your receipt waits in your account.'],
];
$story = 'A quiet place to land in Metro Manila. Clean rooms, a hot rain shower, Wi-Fi that works, and a friendly face at the front desk no matter how late your flight gets in.';
?>
<!-- 1. Hero: rounded photo frame, header on top, brand word behind the skyline, booking card in frosted glass -->
<section class="hero relative isolate overflow-hidden bg-ink-950 text-white" aria-labelledby="hero-h">
  <img src="/assets/img/hero-metro-manila-night.webp" alt="" class="hero-photo absolute inset-0 -z-30 size-full object-cover object-[50%_60%]" width="1920" height="1440" fetchpriority="high">
  <div class="absolute inset-0 -z-10 bg-gradient-to-t from-ink-950 via-ink-950/60 to-ink-950/30"></div>
  <div class="absolute inset-0 -z-10 bg-gradient-to-r from-ink-950/75 via-ink-950/25 to-transparent"></div>
  <p class="hero-word pointer-events-none absolute inset-x-0 top-24 -z-20 select-none whitespace-nowrap text-center font-display text-[17vw] font-semibold leading-none tracking-tighter text-white/10 sm:top-20 lg:top-16" aria-hidden="true">MA HOTEL</p>

  <div class="container-page grid min-h-[44rem] items-end gap-10 pb-10 pt-36 sm:min-h-[46rem] sm:pb-14 lg:min-h-[48rem] lg:grid-cols-12 lg:gap-8">
    <div class="lg:col-span-7">
      <p class="rise [--i:0] eyebrow text-white/85 before:bg-clay-400">Boutique hotel &middot; Front desk open all night</p>
      <h1 id="hero-h" class="mt-5 font-display text-5xl font-medium leading-[0.98] tracking-tight text-white sm:text-6xl lg:text-7xl xl:text-8xl">
        <span class="rise [--i:1] block">A boutique hotel</span>
        <span class="rise [--i:2] block text-white/70">in Metro Manila</span>
      </h1>
      <p class="rise [--i:3] text-pretty mt-6 max-w-lg text-base text-white/90 sm:text-lg">Every room has a rain shower and free Wi-Fi. Land late and someone is still at the desk to hand you your key.</p>
      <ul class="rise [--i:4] mt-6 flex flex-wrap gap-2 text-sm" aria-label="Why book with us">
        <?php foreach (['Free cancellation up to 48 hours', 'Card, GCash or Maya', 'Breakfast with Deluxe and Suites'] as $p) { ?>
          <li class="glass-dark inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-white ring-1 ring-white/20"><?= icon('check', 'size-4 text-clay-300') ?> <?= e($p) ?></li>
        <?php } ?>
      </ul>
    </div>

    <form id="find" action="/search" method="get" class="hero-card glass-dark scroll-mt-6 rounded-[1.75rem] p-5 ring-1 ring-white/20 sm:p-6 lg:col-span-5" data-date-pair aria-labelledby="find-h">
      <h2 id="find-h" class="font-display text-2xl font-medium text-white sm:text-3xl">Plan your stay</h2>
      <p class="mt-1 text-sm text-white/85">Choose your dates to see which rooms are free.</p>
      <div class="mt-5 grid grid-cols-2 gap-2">
        <div class="hero-well col-span-2 sm:col-span-1"><label class="label" for="h-in"><?= icon('calendar', 'size-4') ?> Check-in</label><input class="input" id="h-in" name="checkIn" type="date" required min="<?= e(today()) ?>" value="<?= e($form['checkIn']) ?>" data-checkin></div>
        <div class="hero-well col-span-2 sm:col-span-1"><label class="label" for="h-out"><?= icon('calendar', 'size-4') ?> Check-out</label><input class="input" id="h-out" name="checkOut" type="date" required min="<?= e(plus_days(today(), 1)) ?>" value="<?= e($form['checkOut']) ?>" data-checkout></div>
        <div class="hero-well"><label class="label" for="h-adults"><?= icon('users', 'size-4') ?> Guests</label><select class="input" id="h-adults" name="adults" data-stepper><?php for ($i = 1; $i <= 8; $i++) { echo '<option value="' . $i . '"' . ($i === 2 ? ' selected' : '') . '>' . $i . ($i === 1 ? ' guest' : ' guests') . '</option>'; } ?></select></div>
        <div class="hero-well"><label class="label" for="h-rooms"><?= icon('bed', 'size-4') ?> Rooms</label><select class="input" id="h-rooms" name="rooms" data-stepper><?php for ($i = 1; $i <= 5; $i++) { echo '<option value="' . $i . '"' . ($i === 1 ? ' selected' : '') . '>' . $i . ($i === 1 ? ' room' : ' rooms') . '</option>'; } ?></select></div>
      </div>
      <dl class="mt-2 grid grid-cols-2 divide-x divide-white/15 rounded-2xl bg-ink-950/45 px-4 py-3 text-sm">
        <div><dt class="text-white/75">Check-in</dt><dd class="font-medium text-white">After 2:00 PM</dd></div>
        <div class="pl-4"><dt class="text-white/75">Check-out</dt><dd class="font-medium text-white">Until 12:00 noon</dd></div>
      </dl>
      <button class="btn btn-primary btn-lg mt-4 w-full">Find a room <?= icon('arrow', 'size-4') ?></button>
      <p class="mt-3 text-center text-xs text-white/85">Free cancellation up to 48 hours before you arrive.</p>
    </form>
  </div>
</section>

<!-- 2. Story: the statement turns to ink as you read down the page -->
<section class="container-page mt-24 sm:mt-32" aria-labelledby="about-h">
  <div class="grid gap-8 lg:grid-cols-12 lg:gap-16">
    <div class="lg:col-span-4">
      <h2 id="about-h" class="eyebrow">Our story</h2>
      <p class="text-pretty mt-6 max-w-xs text-base text-ink-700">Pick your dates, see the real price, and book in a few minutes. No calls, no surprises at check-in.</p>
      <a href="/rooms" class="btn btn-secondary btn-arrow mt-6">See the rooms <span class="btn-chip"><?= icon('arrow', 'size-4') ?></span></a>
    </div>
    <p class="text-balance font-display text-3xl font-medium leading-tight tracking-tight text-ink-950 sm:text-4xl lg:col-span-8 lg:text-5xl" data-words><?= e($story) ?></p>
  </div>
  <dl class="mt-16 grid grid-cols-2 gap-y-8 border-t border-ink-200 pt-8 sm:mt-20 lg:grid-cols-4">
    <?php foreach ([['10 min', 'to pay while we hold your room'], ['3', 'ways to pay: card, GCash, Maya'], ['24 h', 'front desk, every day'], ['48 h', 'free cancellation before arrival']] as [$v, $l]) { ?>
      <div class="pr-4"><dt class="sr-only"><?= e($l) ?></dt><dd class="stat-value"><?= e($v) ?></dd><dd class="stat-label"><?= e($l) ?></dd></div>
    <?php } ?>
  </dl>
</section>

<!-- 3. Rooms: tiles that open up the one you point at (two a row on a tablet) -->
<section class="container-page mt-24 sm:mt-32" aria-labelledby="rooms-h">
  <div class="flex flex-wrap items-end justify-between gap-6">
    <div class="max-w-2xl">
      <h2 id="rooms-h" class="section-title">Choose the room that fits your trip.</h2>
      <p class="text-pretty mt-4 text-ink-700">Standard rooms for one or two, Deluxe corner rooms with breakfast for two, and Suites with a separate living room and breakfast for four.</p>
    </div>
    <a href="/rooms" class="btn btn-secondary btn-arrow">Compare the rooms <span class="btn-chip"><?= icon('arrow', 'size-4') ?></span></a>
  </div>
  <ul class="room-row mt-10 grid gap-4 md:grid-cols-2 lg:flex lg:h-[32rem]">
    <?php foreach ($types as $i => $t) { $wide = $i === count($types) - 1 && count($types) % 2 === 1; ?>
      <li class="room-tile group relative isolate flex min-h-[24rem] flex-col overflow-hidden rounded-3xl bg-ink-900 lg:min-h-0<?= $wide ? ' md:col-span-2' : '' ?>">
        <img src="/assets/img/<?= e($t['image']) ?>" alt="" class="zoom-img absolute inset-0 -z-20 size-full object-cover" width="1200" height="800" loading="lazy">
        <div class="absolute inset-0 -z-10 bg-gradient-to-t from-ink-950/90 via-ink-950/30 to-transparent"></div>
        <span class="absolute left-4 top-4 rounded-full bg-white/95 px-3 py-1 text-xs font-semibold text-ink-900"><?= e($t['sizeSqm']) ?> sqm &middot; sleeps <?= e($t['maxGuests']) ?></span>
        <div class="flex flex-1 flex-col justify-end p-6">
          <h3 class="font-display text-3xl font-medium tracking-tight text-white"><a href="/rooms/<?= e($t['slug']) ?>" class="after:absolute after:inset-0 focus-visible:outline-white"><?= e($t['name']) ?><span class="sr-only"> room</span></a></h3>
          <p class="mt-1 text-white/90"><?= e($t['tagline']) ?></p>
          <p class="room-more mt-2 flex items-center gap-1.5 text-sm text-white/85"><?= icon('bed', 'size-4') ?> <?= e($t['bedType']) ?></p>
        </div>
      </li>
    <?php } ?>
  </ul>
</section>

<!-- 4. A stay in four photographs: pinned while you scroll (app.js); four cards in a row without it -->
<section class="chapters mt-24 sm:mt-32" aria-labelledby="stay-h" data-chapters>
  <div class="chapters-box container-page">
    <div class="flex flex-wrap items-end justify-between gap-6">
      <div class="max-w-2xl">
        <p class="eyebrow">Book with the hotel</p>
        <h2 id="stay-h" class="section-title mt-3">Your stay, from booking to breakfast.</h2>
        <p class="text-pretty mt-4 text-ink-700">Four steps, from this page to the breakfast table. You deal with the hotel itself the whole way.</p>
      </div>
      <a href="#find" class="btn btn-primary btn-arrow">Find a room <span class="btn-chip"><?= icon('arrow', 'size-4') ?></span></a>
    </div>
    <ol class="chapters-list">
      <?php foreach ($chapters as $k => [$img, $w, $h, $title, $text]) { ?>
        <li class="chapter">
          <figure class="chapter-pic"><img src="/assets/img/<?= e($img) ?>" alt="" width="<?= $w ?>" height="<?= $h ?>" loading="lazy"></figure>
          <div class="chapter-words">
            <h3 class="text-xl font-semibold text-ink-950"><span class="chapter-num mr-2 text-clay-700"><?= sprintf('%02d', $k + 1) ?></span><?= e($title) ?></h3>
            <p class="text-pretty mt-2 text-ink-700"><?= e($text) ?></p>
          </div>
        </li>
      <?php } ?>
    </ol>
  </div>
</section>

<!-- 5. Included -->
<section class="container-page mt-24 sm:mt-32" aria-labelledby="inc-h">
  <div class="grid gap-10 lg:grid-cols-12 lg:items-start">
    <div class="lg:col-span-4">
      <h2 id="inc-h" class="section-title">What every stay includes.</h2>
      <p class="text-pretty mt-4 text-ink-700">The everyday things, done properly in all forty-five rooms.</p>
    </div>
    <ul class="grid gap-4 sm:grid-cols-2 lg:col-span-8">
      <?php foreach ([
          ['wifi', 'Free Wi-Fi', 'Stay connected in your room and in the lobby.'],
          ['shower', 'A proper rain shower', 'In every bathroom, with hot water that stays hot.'],
          ['coffee', 'Breakfast with Deluxe and Suites', 'For two with a Deluxe room, for four with a Suite.'],
          ['key', 'In-room safe', 'Lock away your laptop and passport while you are out.'],
          ['clock', 'Front desk, day and night', 'Land late or leave early: someone is there to let you in.'],
          ['shield', 'Your details stay yours', 'See, correct or delete what we keep about you, from your account.'],
      ] as [$i, $h, $p]) { ?>
        <li class="lift card flex gap-4 p-5"><span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-clay-50 text-clay-700"><?= icon($i, 'size-5') ?></span><div class="min-w-0"><h3 class="font-semibold"><?= e($h) ?></h3><p class="mt-1 text-sm text-ink-600"><?= e($p) ?></p></div></li>
      <?php } ?>
    </ul>
  </div>
</section>

<!-- 6. FAQ. Photo on top as a banner on phones and tablets; pinned beside the questions on desktop. Its size never depends on how many answers are open. -->
<section class="container-page mt-24 sm:mt-32" aria-labelledby="faq-h">
  <p class="eyebrow">Questions</p>
  <h2 id="faq-h" class="section-title mt-3 max-w-2xl">Questions guests ask before they book.</h2>
  <div class="mt-8 grid gap-6 sm:mt-10 lg:grid-cols-12 lg:gap-10">
    <figure class="photo aspect-[16/9] w-full sm:aspect-[2/1] lg:sticky lg:top-24 lg:col-span-5 lg:aspect-[4/5] lg:self-start lg:order-2"><img src="/assets/img/room-suite.webp" alt="" class="size-full object-cover" width="1200" height="800" loading="lazy" sizes="(min-width: 1024px) 40vw, 100vw"></figure>
    <div class="min-w-0 space-y-3 lg:order-1 lg:col-span-7">
      <?php foreach ($faq as $k => [$q, $a]) { ?>
        <details class="group/faq card px-4 py-1 sm:px-6"<?= $k === 0 ? ' open' : '' ?>>
          <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-3 py-2 text-left text-base font-semibold text-ink-950 sm:gap-4 sm:text-lg [&::-webkit-details-marker]:hidden"><span class="min-w-0 text-pretty"><?= e($q) ?></span><span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-ink-100 text-ink-800 transition-transform duration-200 group-open/faq:rotate-45" aria-hidden="true"><?= icon('x', 'size-4 rotate-45') ?></span></summary>
          <p class="text-pretty max-w-prose pb-5 text-sm text-ink-600 sm:text-base"><?= e($a) ?></p>
        </details>
      <?php } ?>
    </div>
  </div>
</section>

<!-- 7. Closing call to action -->
<section class="container-page mt-24 sm:mt-32" aria-labelledby="cta-h">
  <div class="relative isolate overflow-hidden rounded-[2rem] bg-ink-950 px-6 py-16 text-center text-white sm:px-12 sm:py-24">
    <img src="/assets/img/hero-manila-night.webp" alt="" class="absolute inset-0 -z-20 size-full object-cover" width="3008" height="784" loading="lazy">
    <div class="absolute inset-0 -z-10 bg-ink-950/70"></div>
    <h2 id="cta-h" class="text-balance mx-auto max-w-2xl font-display text-4xl font-medium tracking-tight text-white sm:text-5xl">Sleep well on your next trip to Manila.</h2>
    <p class="text-pretty mx-auto mt-4 max-w-xl text-lg text-white/90">Book direct for live prices, free cancellation up to 48 hours before you arrive, and your confirmation by email as soon as you pay.</p>
    <div class="mt-8 flex flex-wrap justify-center gap-3"><a href="#find" class="btn btn-lg btn-primary btn-arrow">Find a room <span class="btn-chip"><?= icon('arrow', 'size-4') ?></span></a><a href="/register" class="btn btn-lg btn-outline-light">Create an account</a></div>
  </div>
</section>
