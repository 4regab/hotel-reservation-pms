<?php /** @var Portal\Web\Ctx $c @var array $t @var array $stay @var string $quote @var int $nights @var int $total @var string $idem @var string $back @var ?array $account */ ?>
<div class="container-page max-w-4xl py-8">
  <nav aria-label="Booking steps" class="mb-6"><ol class="flex flex-wrap gap-2 text-sm">
    <li class="badge badge-slate">1 Choose</li><li class="badge badge-blue" aria-current="step">2 Your details</li><li class="badge badge-slate">3 Pay</li><li class="badge badge-slate">4 Confirmed</li>
  </ol></nav>
  <h1 class="text-3xl font-bold">Your details</h1>
  <div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2">
      <?= form_open($c, '/book/hold', 'card card-pad space-y-4') ?>
        <input type="hidden" name="quoteId" value="<?= e($quote) ?>"><input type="hidden" name="roomTypeId" value="<?= e($t['roomTypeId']) ?>">
        <input type="hidden" name="checkIn" value="<?= e($stay['checkIn']) ?>"><input type="hidden" name="checkOut" value="<?= e($stay['checkOut']) ?>"><input type="hidden" name="rooms" value="<?= e($stay['rooms']) ?>"><input type="hidden" name="idem" value="<?= e($idem) ?>">
        <?= field('Full name', 'fullName', 'text', (string) ($account['full_name'] ?? ''), 'required maxlength="120" autocomplete="name"') ?>
        <div class="grid gap-4 sm:grid-cols-2">
          <?= field('Mobile number', 'phone', 'tel', (string) ($account['phone'] ?? ''), 'required maxlength="40" autocomplete="tel"', 'We only call if something about your stay changes.') ?>
          <div><label class="label" for="f-adults">Guests</label><input class="input" id="f-adults" name="adults" type="number" min="1" max="<?= e($t['maxGuests'] * (int) $stay['rooms']) ?>" value="<?= e(min((int) $stay['adults'], $t['maxGuests'] * (int) $stay['rooms'])) ?>" required><p class="hint">Up to <?= e($t['maxGuests'] * (int) $stay['rooms']) ?> for this booking.</p></div>
        </div>
        <p class="text-sm text-slate-700">Confirmation goes to <strong><?= e($account['email'] ?? '') ?></strong>.</p>
        <?= textarea_field('Special requests (optional)', 'specialRequests', '', 'maxlength="300"', 'We pass requests to the front desk. We cannot always promise them.') ?>
        <div class="alert alert-info"><strong>Next:</strong> we hold this room for <strong>10 minutes</strong> while you pay. If the time runs out the room goes back on sale.</div>
        <div class="flex flex-wrap gap-3"><button class="btn btn-primary">Hold room and continue</button><a class="btn btn-secondary" href="<?= e($back) ?>">Back to results</a></div>
      </form>
    </div>
    <aside class="card card-pad h-fit">
      <img src="/assets/img/<?= e($t['image']) ?>" alt="" class="mb-3 aspect-[3/2] w-full rounded-lg object-cover" width="800" height="520">
      <h2 class="text-lg font-semibold"><?= e($t['name']) ?><?= (int) $stay['rooms'] > 1 ? ' x ' . e($stay['rooms']) : '' ?></h2>
      <dl class="mt-3 space-y-2 text-sm">
        <div class="flex justify-between gap-2"><dt class="text-slate-600">Check-in</dt><dd class="font-medium"><?= e(d($stay['checkIn'])) ?></dd></div>
        <div class="flex justify-between gap-2"><dt class="text-slate-600">Check-out</dt><dd class="font-medium"><?= e(d($stay['checkOut'])) ?></dd></div>
        <div class="flex justify-between gap-2"><dt class="text-slate-600">Nights</dt><dd class="font-medium"><?= e($nights) ?></dd></div>
        <div class="flex justify-between gap-2 border-t border-slate-200 pt-2 text-base"><dt class="font-semibold">Total</dt><dd class="font-bold text-brand-900"><?= e(php_money($total)) ?></dd></div>
      </dl>
      <p class="mt-3 text-xs text-slate-600">Free cancellation until 48 hours before arrival. After that the first night is charged.</p>
    </aside>
  </div>
</div>
