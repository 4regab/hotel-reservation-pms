<?php /** @var Portal\Web\Ctx $c @var string $sessionId @var string $reservationId */ ?>
<div class="container-page max-w-lg pb-4 pt-8 sm:pt-12">
  <div class="alert alert-warn mb-4 flex gap-3"><?= icon('alert', 'size-5 shrink-0') ?><p><strong>Demo payment page.</strong> This stands in for the PayMongo hosted checkout so the site works without keys. No card is used and no money moves.</p></div>
  <?= form_open($c, '/mock/paymongo/checkout/' . $sessionId, 'card card-pad space-y-4') ?>
    <input type="hidden" name="reservationId" value="<?= e($reservationId) ?>">
    <span class="flex size-12 items-center justify-center rounded-full bg-ink-900 text-white"><?= icon('shield', 'size-6') ?></span>
    <h1 class="font-display text-3xl font-medium tracking-tight">Secure payment</h1>
    <p class="text-sm text-ink-600">Session <span class="break-all font-mono"><?= e($sessionId) ?></span></p>
    <button class="btn btn-primary btn-lg w-full">Pay now (demo)</button>
    <a class="btn btn-secondary btn-lg w-full" href="/book/pay/<?= e($reservationId) ?>">Go back</a>
  </form>
</div>
