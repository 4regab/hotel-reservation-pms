<?php /** @var Portal\Web\Ctx $c @var array $form @var ?array $res @var ?string $error @var bool $searched */ ?>
<div class="container-page pb-4 pt-8 sm:pt-12">
  <p class="eyebrow">Book a room</p>
  <h1 class="page-title mt-3">Find a room</h1>
  <div class="search-bar mt-6"><?php include __DIR__ . '/partials/search_form.php'; ?></div>

  <?php if ($error) { ?><p class="alert alert-error mt-6" role="alert"><?= e($error) ?></p><?php } ?>

  <?php if ($res) { ?>
    <div class="mt-10 flex flex-wrap items-baseline justify-between gap-2">
      <h2 class="text-2xl font-medium" id="results"><?= e(d($res['checkIn'])) ?> to <?= e(d($res['checkOut'])) ?></h2>
      <p class="text-sm text-ink-600"><?= e($res['nights']) ?> <?= $res['nights'] === 1 ? 'night' : 'nights' ?>, <?= e($res['adults']) ?> <?= $res['adults'] === 1 ? 'guest' : 'guests' ?>, <?= e($res['quantity']) ?> <?= $res['quantity'] === 1 ? 'room' : 'rooms' ?></p>
    </div>
    <div class="mt-4 space-y-4" aria-labelledby="results">
      <?php foreach ($res['options'] as $o) { $can = $o['quoteId'] !== null; ?>
        <article class="card overflow-hidden p-2 md:grid md:grid-cols-12 md:gap-2">
          <div class="photo md:col-span-4 lg:col-span-3"><img src="/assets/img/<?= e($o['image']) ?>" alt="" class="aspect-[3/2] w-full object-cover md:aspect-auto md:h-full <?= $can ? '' : 'grayscale' ?>" width="800" height="520" loading="lazy"></div>
          <div class="p-4 sm:p-5 md:col-span-5 lg:col-span-6">
            <h3 class="font-display text-2xl font-medium tracking-tight"><a class="hover:underline" href="/rooms/<?= e($o['slug']) ?>"><?= e($o['name']) ?></a></h3>
            <p class="mt-1 text-sm text-ink-600"><?= e($o['tagline']) ?></p>
            <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink-700">
              <li class="flex items-center gap-1.5"><?= icon('expand', 'size-4') ?> <?= e($o['sizeSqm']) ?> sqm</li>
              <li class="flex items-center gap-1.5"><?= icon('bed', 'size-4') ?> <?= e($o['bedType']) ?></li>
              <li class="flex items-center gap-1.5"><?= icon('users', 'size-4') ?> Sleeps <?= e($o['maxGuests']) ?></li>
            </ul>
            <ul class="mt-3 flex flex-wrap gap-1.5"><?php foreach (array_slice($o['amenities'], 0, 4) as $a) { ?><li class="chip"><?= e($a) ?></li><?php } ?></ul>
            <?php if (!$o['fits']) { ?><p class="mt-3 text-sm font-medium text-amber-800">Too small for <?= e($res['adults']) ?> guests in <?= e($res['quantity']) ?> <?= $res['quantity'] === 1 ? 'room' : 'rooms' ?>. Add a room or pick a bigger type.</p>
            <?php } elseif (!$can) { ?><p class="mt-3 text-sm font-semibold text-red-800">Sold out for these dates</p>
            <?php } elseif ($o['available'] <= 3) { ?><p class="mt-3 text-sm font-semibold text-amber-800">Only <?= e($o['available']) ?> left</p><?php } ?>
          </div>
          <div class="flex flex-col justify-between gap-4 rounded-2xl bg-ink-50 p-4 sm:p-5 md:col-span-3">
            <div>
              <p class="text-sm text-ink-600"><?= e(php_money($o['nightlyMinor'])) ?> per night</p>
              <p class="mt-1 font-display text-3xl font-medium tracking-tight text-ink-950"><?= e(php_money($o['totalMinor'])) ?></p>
              <p class="text-xs text-ink-600">total for <?= e($res['nights']) ?> <?= $res['nights'] === 1 ? 'night' : 'nights' ?>, taxes included</p>
            </div>
            <?php if ($can) { ?>
              <?= form_open($c, '/book') ?>
                <input type="hidden" name="quoteId" value="<?= e($o['quoteId']) ?>"><input type="hidden" name="roomTypeId" value="<?= e($o['roomTypeId']) ?>">
                <input type="hidden" name="checkIn" value="<?= e($res['checkIn']) ?>"><input type="hidden" name="checkOut" value="<?= e($res['checkOut']) ?>">
                <input type="hidden" name="adults" value="<?= e($res['adults']) ?>"><input type="hidden" name="rooms" value="<?= e($res['quantity']) ?>"><input type="hidden" name="totalMinor" value="<?= e($o['totalMinor']) ?>">
                <button class="btn btn-primary w-full">Select<span class="sr-only"> <?= e($o['name']) ?></span></button>
              </form>
            <?php } else { ?><button class="btn btn-secondary w-full" disabled>Unavailable</button><?php } ?>
          </div>
        </article>
      <?php } ?>
    </div>
    <p class="mt-4 text-sm text-ink-600">Prices stay valid for 15 minutes. Nothing is reserved until you press the hold button on the next step.</p>
  <?php } elseif (!$searched) { ?>
    <div class="empty mt-8"><span class="empty-icon"><?= icon('calendar', 'size-6') ?></span><p>Pick your dates to see live prices for each room type.</p></div>
  <?php } ?>
</div>
