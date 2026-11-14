<?php /** @var array $form expects checkIn, checkOut, adults, rooms. Set $compact = true for a narrow sidebar. */
$form = $form ?? ['checkIn' => plus_days(today(), 1), 'checkOut' => plus_days(today(), 3), 'adults' => 2, 'rooms' => 1];
$compact = $compact ?? false; ?>
<form action="/search" method="get" class="grid grid-cols-2 items-stretch gap-2 <?= $compact ? '' : 'lg:grid-cols-[1fr_1fr_11.5rem_11.5rem_auto]' ?>" data-date-pair>
  <div class="field-well <?= $compact ? 'col-span-2' : 'col-span-2 sm:col-span-1' ?>">
    <label class="label" for="s-in">Check-in</label>
    <input class="input" id="s-in" name="checkIn" type="date" required min="<?= e(today()) ?>" value="<?= e($form['checkIn']) ?>" data-checkin>
  </div>
  <div class="field-well <?= $compact ? 'col-span-2' : 'col-span-2 sm:col-span-1' ?>">
    <label class="label" for="s-out">Check-out</label>
    <input class="input" id="s-out" name="checkOut" type="date" required min="<?= e(plus_days(today(), 1)) ?>" value="<?= e($form['checkOut']) ?>" data-checkout>
  </div>
  <div class="field-well">
    <label class="label" for="s-adults">Guests</label>
    <select class="input" id="s-adults" name="adults" data-stepper><?php for ($i = 1; $i <= 8; $i++) { echo '<option value="' . $i . '"' . ((int) $form['adults'] === $i ? ' selected' : '') . '>' . $i . ($i === 1 ? ' guest' : ' guests') . '</option>'; } ?></select>
  </div>
  <div class="field-well">
    <label class="label" for="s-rooms">Rooms</label>
    <select class="input" id="s-rooms" name="rooms" data-stepper><?php for ($i = 1; $i <= 5; $i++) { echo '<option value="' . $i . '"' . ((int) $form['rooms'] === $i ? ' selected' : '') . '>' . $i . ($i === 1 ? ' room' : ' rooms') . '</option>'; } ?></select>
  </div>
  <button class="btn btn-primary btn-lg col-span-2 <?= $compact ? '' : 'lg:col-span-1 lg:h-auto lg:px-7' ?>"><?= icon('search', 'size-5') ?> Check availability</button>
</form>
