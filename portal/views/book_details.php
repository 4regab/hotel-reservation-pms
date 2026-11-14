<?php /** @var Portal\Web\Ctx $c @var array $t @var array $stay @var string $quote @var int $nights @var int $total @var string $idem @var string $back @var ?array $account */ ?>
<div class="container-page max-w-6xl pb-4 pt-8 sm:pt-12">
  <?php $step = 2; include __DIR__ . '/partials/steps.php'; ?>
  <h1 class="page-title">Your details</h1>
  <div class="mt-8 grid gap-6 lg:grid-cols-12 lg:gap-8">
    <div class="lg:col-span-7">
      <?= form_open($c, '/book/hold', 'card card-pad space-y-5') ?>
        <input type="hidden" name="quoteId" value="<?= e($quote) ?>"><input type="hidden" name="roomTypeId" value="<?= e($t['roomTypeId']) ?>">
        <input type="hidden" name="checkIn" value="<?= e($stay['checkIn']) ?>"><input type="hidden" name="checkOut" value="<?= e($stay['checkOut']) ?>"><input type="hidden" name="rooms" value="<?= e($stay['rooms']) ?>"><input type="hidden" name="idem" value="<?= e($idem) ?>">
        <?= field('Full name', 'fullName', 'text', (string) ($account['full_name'] ?? ''), 'required maxlength="120" autocomplete="name"') ?>
        <div class="grid gap-5 sm:grid-cols-2">
          <?= field('Mobile number', 'phone', 'tel', (string) ($account['phone'] ?? ''), 'required maxlength="40" autocomplete="tel"', 'We only call if something about your stay changes.') ?>
          <div><label class="label" for="f-adults">Guests</label><input class="input" id="f-adults" name="adults" type="number" min="1" max="<?= e($t['maxGuests'] * (int) $stay['rooms']) ?>" value="<?= e(min((int) $stay['adults'], $t['maxGuests'] * (int) $stay['rooms'])) ?>" required><p class="hint">Up to <?= e($t['maxGuests'] * (int) $stay['rooms']) ?> for this booking.</p></div>
        </div>
        <p class="rounded-xl bg-ink-50 px-4 py-3 text-sm text-ink-700">Confirmation goes to <strong class="break-all text-ink-950"><?= e($account['email'] ?? '') ?></strong>.</p>
        <?= textarea_field('Special requests (optional)', 'specialRequests', '', 'maxlength="300"', 'We pass requests to the front desk. We cannot always promise them.') ?>
        <div class="alert alert-info flex gap-3"><?= icon('clock', 'size-5 shrink-0') ?><p><strong>Next:</strong> we hold this room for <strong>10 minutes</strong> while you pay. If the time runs out the room goes back on sale.</p></div>
        <div class="flex flex-wrap gap-3 pt-1"><button class="btn btn-primary btn-lg">Hold room and continue</button><a class="btn btn-secondary btn-lg" href="<?= e($back) ?>">Back to results</a></div>
      </form>
    </div>
    <aside class="lg:col-span-5" aria-labelledby="sum-h">
      <div class="card p-2 lg:sticky lg:top-6">
        <div class="photo"><img src="/assets/img/<?= e($t['image']) ?>" alt="" class="aspect-[3/2] w-full object-cover" width="800" height="520"></div>
        <div class="p-4 sm:p-5">
          <h2 id="sum-h" class="font-display text-2xl font-medium"><?= e($t['name']) ?><?= (int) $stay['rooms'] > 1 ? ' x ' . e($stay['rooms']) : '' ?></h2>
          <dl class="mt-4 space-y-3 text-sm">
            <div class="flex justify-between gap-2"><dt class="text-ink-600">Check-in</dt><dd class="font-medium text-ink-950"><?= e(d($stay['checkIn'])) ?></dd></div>
            <div class="flex justify-between gap-2"><dt class="text-ink-600">Check-out</dt><dd class="font-medium text-ink-950"><?= e(d($stay['checkOut'])) ?></dd></div>
            <div class="flex justify-between gap-2"><dt class="text-ink-600">Nights</dt><dd class="font-medium text-ink-950"><?= e($nights) ?></dd></div>
            <div class="flex items-baseline justify-between gap-2 border-t border-ink-200 pt-3"><dt class="font-semibold text-ink-950">Total</dt><dd class="font-display text-2xl font-medium text-ink-950"><?= e(php_money($total)) ?></dd></div>
          </dl>
          <p class="mt-3 text-xs text-ink-600">Taxes included. Free cancellation until 48 hours before arrival. After that the first night is charged.</p>
        </div>
      </div>
    </aside>
  </div>
</div>
