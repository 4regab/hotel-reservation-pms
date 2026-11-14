<?php /** @var array $form expects checkIn, checkOut, adults, rooms */ $form = $form ?? ['checkIn' => plus_days(today(), 1), 'checkOut' => plus_days(today(), 3), 'adults' => 2, 'rooms' => 1]; ?>
<form action="/search" method="get" class="grid grid-cols-2 items-end gap-3 lg:grid-cols-[1fr_1fr_8rem_8rem_auto]" data-date-pair>
  <div class="col-span-1">
    <label class="label" for="s-in">Check-in</label>
    <input class="input" id="s-in" name="checkIn" type="date" required min="<?= e(today()) ?>" value="<?= e($form['checkIn']) ?>" data-checkin>
  </div>
  <div class="col-span-1">
    <label class="label" for="s-out">Check-out</label>
    <input class="input" id="s-out" name="checkOut" type="date" required min="<?= e(plus_days(today(), 1)) ?>" value="<?= e($form['checkOut']) ?>" data-checkout>
  </div>
  <div>
    <label class="label" for="s-adults">Guests</label>
    <select class="input" id="s-adults" name="adults"><?php for ($i = 1; $i <= 8; $i++) { echo '<option value="' . $i . '"' . ((int) $form['adults'] === $i ? ' selected' : '') . '>' . $i . ($i === 1 ? ' guest' : ' guests') . '</option>'; } ?></select>
  </div>
  <div>
    <label class="label" for="s-rooms">Rooms</label>
    <select class="input" id="s-rooms" name="rooms"><?php for ($i = 1; $i <= 5; $i++) { echo '<option value="' . $i . '"' . ((int) $form['rooms'] === $i ? ' selected' : '') . '>' . $i . ($i === 1 ? ' room' : ' rooms') . '</option>'; } ?></select>
  </div>
  <button class="btn btn-primary col-span-2 lg:col-span-1">Check availability</button>
</form>
