<?php /** @var Portal\Web\Ctx $c @var string $sessionId @var string $reservationId */ ?>
<div class="container-page max-w-lg py-10">
  <div class="alert alert-warn mb-4"><strong>Demo payment page.</strong> This stands in for the PayMongo hosted checkout so the site works without keys. No card is used and no money moves.</div>
  <?= form_open($c, '/mock/paymongo/checkout/' . $sessionId, 'card card-pad space-y-4') ?>
    <input type="hidden" name="reservationId" value="<?= e($reservationId) ?>">
    <h1 class="text-2xl font-bold">Secure payment</h1>
    <p class="text-sm text-slate-600">Session <span class="font-mono"><?= e($sessionId) ?></span></p>
    <button class="btn btn-primary w-full">Pay now (demo)</button>
    <a class="btn btn-secondary w-full" href="/book/pay/<?= e($reservationId) ?>">Go back</a>
  </form>
</div>
