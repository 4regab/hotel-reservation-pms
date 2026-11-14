<?php /** @var Portal\Web\Ctx $c @var array $form @var ?array $res @var ?string $error */ ?>
<div class="container-page max-w-5xl py-8">
  <h1 class="text-3xl font-bold">Walk-in booking</h1>
  <p class="mt-1 text-slate-600">Find a room for a guest at the desk. The room is held for 10 minutes while you take payment.</p>
  <div class="card card-pad mt-4">
    <form action="/staff/walk-in" method="get" class="grid grid-cols-2 items-end gap-3 lg:grid-cols-[1fr_1fr_8rem_8rem_auto]" data-date-pair>
      <div><label class="label" for="w-in">Check-in</label><input class="input" id="w-in" name="checkIn" type="date" required min="<?= e(today()) ?>" value="<?= e($form['checkIn']) ?>" data-checkin></div>
      <div><label class="label" for="w-out">Check-out</label><input class="input" id="w-out" name="checkOut" type="date" required min="<?= e(plus_days(today(), 1)) ?>" value="<?= e($form['checkOut']) ?>" data-checkout></div>
      <div><label class="label" for="w-ad">Guests</label><input class="input" id="w-ad" name="adults" type="number" min="1" max="20" value="<?= e($form['adults']) ?>"></div>
      <div><label class="label" for="w-rm">Rooms</label><input class="input" id="w-rm" name="rooms" type="number" min="1" max="5" value="<?= e($form['rooms']) ?>"></div>
      <button class="btn btn-primary col-span-2 lg:col-span-1">Search</button>
    </form>
  </div>
  <?php if ($error) { ?><p class="alert alert-error mt-4" role="alert"><?= e($error) ?></p><?php } ?>
  <?php if ($res) { ?>
    <ul class="mt-6 space-y-4">
      <?php foreach ($res['options'] as $o) { ?>
        <li class="card card-pad">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div><h2 class="text-lg font-semibold"><?= e($o['name']) ?> <span class="text-sm font-normal text-slate-600"><?= e($o['available']) ?> available</span></h2>
              <p class="text-sm text-slate-700"><?= e(php_money($o['nightlyMinor'])) ?> per night &middot; <strong><?= e(php_money($o['totalMinor'])) ?></strong> for <?= e($res['nights']) ?> <?= $res['nights'] === 1 ? 'night' : 'nights' ?></p></div>
            <?php if (!$o['quoteId']) { ?><span class="badge badge-red"><?= $o['fits'] ? 'Sold out' : 'Too small' ?></span><?php } ?>
          </div>
          <?php if ($o['quoteId']) { ?>
            <details class="mt-3"><summary class="btn btn-secondary btn-sm cursor-pointer">Book <?= e($o['name']) ?></summary>
              <?= form_open($c, '/staff/walk-in', 'mt-4 grid gap-3 sm:grid-cols-3 sm:items-end') ?>
                <input type="hidden" name="quoteId" value="<?= e($o['quoteId']) ?>"><input type="hidden" name="roomTypeId" value="<?= e($o['roomTypeId']) ?>">
                <input type="hidden" name="checkIn" value="<?= e($res['checkIn']) ?>"><input type="hidden" name="checkOut" value="<?= e($res['checkOut']) ?>"><input type="hidden" name="adults" value="<?= e($res['adults']) ?>"><input type="hidden" name="rooms" value="<?= e($res['quantity']) ?>">
                <?= field('Guest name', 'fullName', 'text', '', 'required maxlength="120"') ?>
                <?= field('Email', 'email', 'email', '', 'required maxlength="160"') ?>
                <?= field('Phone', 'phone', 'tel', '', 'required maxlength="40"') ?>
                <button class="btn btn-primary sm:col-span-3">Hold room for 10 minutes</button>
              </form>
            </details>
          <?php } ?>
        </li>
      <?php } ?>
    </ul>
  <?php } ?>
</div>
