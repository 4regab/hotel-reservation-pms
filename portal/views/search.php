<?php /** @var Portal\Web\Ctx $c @var array $form @var ?array $res @var ?string $error @var bool $searched */ ?>
<div class="container-page py-8">
  <h1 class="text-3xl font-bold">Find a room</h1>
  <div class="card card-pad mt-4"><?php include __DIR__ . '/partials/search_form.php'; ?></div>

  <?php if ($error) { ?><p class="alert alert-error mt-4" role="alert"><?= e($error) ?></p><?php } ?>

  <?php if ($res) { ?>
    <h2 class="mt-8 text-xl font-semibold" id="results"><?= e(d($res['checkIn'])) ?> to <?= e(d($res['checkOut'])) ?> <span class="text-base font-normal text-slate-600">&middot; <?= e($res['nights']) ?> <?= $res['nights'] === 1 ? 'night' : 'nights' ?>, <?= e($res['adults']) ?> <?= $res['adults'] === 1 ? 'guest' : 'guests' ?>, <?= e($res['quantity']) ?> <?= $res['quantity'] === 1 ? 'room' : 'rooms' ?></span></h2>
    <div class="mt-4 space-y-5" aria-labelledby="results">
      <?php foreach ($res['options'] as $o) { $can = $o['quoteId'] !== null; ?>
        <article class="card overflow-hidden md:grid md:grid-cols-5">
          <img src="/assets/img/<?= e($o['image']) ?>" alt="" class="aspect-[3/2] w-full object-cover md:col-span-1 md:h-full" width="800" height="520" loading="lazy">
          <div class="p-5 md:col-span-3">
            <h3 class="text-xl font-semibold"><a class="hover:underline" href="/rooms/<?= e($o['slug']) ?>"><?= e($o['name']) ?></a></h3>
            <p class="mt-1 text-sm text-slate-700"><?= e($o['sizeSqm']) ?> sqm &middot; <?= e($o['bedType']) ?> &middot; sleeps <?= e($o['maxGuests']) ?></p>
            <p class="mt-2 text-sm text-slate-600"><?= e($o['tagline']) ?></p>
            <ul class="mt-3 flex flex-wrap gap-2"><?php foreach (array_slice($o['amenities'], 0, 4) as $a) { ?><li class="badge badge-slate"><?= e($a) ?></li><?php } ?></ul>
            <?php if (!$o['fits']) { ?><p class="mt-3 text-sm font-medium text-amber-800">Too small for <?= e($res['adults']) ?> guests in <?= e($res['quantity']) ?> <?= $res['quantity'] === 1 ? 'room' : 'rooms' ?>. Add a room or pick a bigger type.</p>
            <?php } elseif (!$can) { ?><p class="mt-3 text-sm font-semibold text-red-800">Sold out for these dates</p>
            <?php } elseif ($o['available'] <= 3) { ?><p class="mt-3 text-sm font-semibold text-amber-800">Only <?= e($o['available']) ?> left</p><?php } ?>
          </div>
          <div class="flex flex-col justify-between gap-3 border-t border-slate-200 bg-slate-50 p-5 md:col-span-1 md:border-l md:border-t-0">
            <div>
              <p class="text-sm text-slate-600"><?= e(php_money($o['nightlyMinor'])) ?> per night</p>
              <p class="text-2xl font-bold text-brand-900"><?= e(php_money($o['totalMinor'])) ?></p>
              <p class="text-xs text-slate-600">total for <?= e($res['nights']) ?> <?= $res['nights'] === 1 ? 'night' : 'nights' ?>, taxes included</p>
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
    <p class="mt-4 text-sm text-slate-600">Prices stay valid for 15 minutes. Nothing is reserved until you press the hold button on the next step.</p>
  <?php } elseif (!$searched) { ?>
    <p class="mt-6 text-slate-600">Pick your dates to see live prices for each room type.</p>
  <?php } ?>
</div>
