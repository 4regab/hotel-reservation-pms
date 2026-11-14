<?php /** @var Portal\Web\Ctx $c @var array $types */ ?>
<div class="container-page max-w-4xl pb-4 pt-8 sm:pt-10">
  <h1 class="page-title">Rates and room types</h1>
  <p class="mt-1 text-ink-600">Rates are per room per night in pesos, taxes included. A new rate applies to new searches. Bookings already made keep the price they were given.</p>
  <div class="mt-6 space-y-6">
    <?php foreach ($types as $t) { ?>
      <?= form_open($c, '/staff/settings/room-types/' . $t['roomTypeId'], 'card card-pad grid gap-4 sm:grid-cols-2') ?>
        <h2 class="text-xl font-semibold sm:col-span-2"><?= e($t['name']) ?> <span class="text-sm font-normal text-ink-600">&middot; <?= e($t['capacity']) ?> rooms</span></h2>
        <?= field('Nightly rate (PHP)', 'rate', 'text', number_format($t['nightlyMinor'] / 100, 2, '.', ''), 'required inputmode="decimal"', 'Between PHP 1,000 and PHP 50,000.') ?>
        <?= field('Tagline', 'tagline', 'text', $t['tagline'], 'required maxlength="120"') ?>
        <div class="sm:col-span-2"><?= textarea_field('Description', 'description', $t['description'], 'required maxlength="700"') ?></div>
        <div class="sm:col-span-2 flex flex-wrap items-center justify-between gap-3"><?= checkbox_field('For sale on the website', 'active', $t['active']) ?><button class="btn btn-primary">Save <?= e($t['name']) ?></button></div>
      </form>
    <?php } ?>
  </div>
</div>
