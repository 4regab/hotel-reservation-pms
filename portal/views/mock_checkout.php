<?php /** @var Portal\Web\Ctx $c @var array $s @var array $r @var string $method @var string $step @var array $errors @var array $keep @var int $secondsLeft @var array $cards */
$sid = (string) $s['sessionId'];
$action = '/mock/paymongo/checkout/' . rawurlencode($sid);
$err = fn (string $f): string => isset($errors[$f]) ? '<p class="mt-1.5 text-sm font-medium text-red-800" id="e-' . e($f) . '">' . e($errors[$f]) . '</p>' : '';
$aria = fn (string $f): string => isset($errors[$f]) ? ' aria-invalid="true" aria-describedby="e-' . e($f) . '"' : '';
$outcomeLabel = ['paid' => 'Payment succeeds', '3ds' => 'Asks you to authenticate (choose Approve or Fail)', 'expired' => 'Declined: expired card', 'invalid_cvc' => 'Declined: wrong security code', 'insufficient' => 'Declined: insufficient funds', 'declined' => 'Declined by the bank'];
$wallet = ['gcash' => 'GCash', 'paymaya' => 'Maya'];
?>
<div class="mx-auto w-full max-w-5xl px-4 pb-4 pt-6 sm:px-6 sm:pt-8">
  <p class="flex items-center gap-2 rounded-2xl border-2 border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-950" role="note"><?= icon('alert', 'size-5 shrink-0') ?> TEST MODE: no real money is charged. This page stands in for PayMongo test mode and only accepts test cards.</p>

  <div class="mt-6 grid gap-6 lg:grid-cols-12">
    <aside class="lg:order-2 lg:col-span-5" aria-labelledby="co-sum">
      <div class="card card-pad lg:sticky lg:top-6">
        <p class="text-sm text-ink-600">Paying MA Hotel</p>
        <h2 id="co-sum" class="mt-1 font-display text-2xl font-medium"><?= e($r['roomTypeName']) ?><?= $r['quantity'] > 1 ? ' x ' . e($r['quantity']) : '' ?></h2>
        <dl class="mt-4 space-y-2 text-sm">
          <div class="flex justify-between gap-3"><dt class="text-ink-600">Booking code</dt><dd class="font-mono font-medium text-ink-950"><?= e($r['code']) ?></dd></div>
          <div class="flex justify-between gap-3"><dt class="text-ink-600">Stay</dt><dd class="text-right font-medium text-ink-950"><?= e(d($r['checkIn'])) ?> to <?= e(d($r['checkOut'])) ?></dd></div>
        </dl>
        <dl class="mt-4 space-y-2 border-t border-ink-100 pt-4 text-sm"><?= price_rows((int) $s['amountMinor'], Portal\Fmt::nights($r['checkIn'], $r['checkOut']), (int) $r['quantity']) ?></dl>
        <p class="mt-4 rounded-xl bg-ink-50 px-3 py-2 text-sm text-ink-700" data-countdown="<?= e($secondsLeft) ?>" data-expired-url="/book/pay/<?= e($r['reservationId']) ?>">Your room is held for <strong class="tabular-nums" data-countdown-text><?= e(sprintf('%d:%02d', intdiv($secondsLeft, 60), $secondsLeft % 60)) ?></strong> more. <span class="sr-only" data-countdown-live role="timer" aria-live="off"></span><span data-countdown-note></span></p>
      </div>
    </aside>

    <section class="lg:order-1 lg:col-span-7" aria-labelledby="co-h">
      <div class="card card-pad">
        <h1 id="co-h" class="font-display text-3xl font-medium tracking-tight"><?= $step === '3ds' ? 'Authenticate payment' : 'Choose how to pay' ?></h1>

        <?php if ($step === '3ds') { ?>
          <p class="mt-2 text-ink-700">Your bank asks you to confirm this payment of <strong><?= e(php_money((int) $s['amountMinor'])) ?></strong> with your <?= e($keep['brand']) ?> card ending in <strong><?= e($keep['last4']) ?></strong>.</p>
          <p class="mt-1 text-sm text-ink-600">In test mode, choose what the bank should answer.</p>
          <?= form_open($c, $action, 'mt-6 flex flex-wrap gap-3', 'data-processing') ?>
            <input type="hidden" name="method" value="card"><input type="hidden" name="threeDsToken" value="<?= e($keep['threeDsToken']) ?>">
            <button class="btn btn-primary btn-lg" name="decision" value="approve">Approve</button>
            <button class="btn btn-secondary btn-lg" name="decision" value="fail">Fail</button>
          </form>
        <?php } else { ?>
          <nav aria-label="Payment method" class="mt-5 grid grid-cols-3 gap-2 rounded-2xl bg-ink-100 p-1">
            <?php foreach (['card' => 'Card', 'gcash' => 'GCash', 'paymaya' => 'Maya'] as $k => $label) { ?>
              <a href="<?= e($action) ?>?method=<?= e($k) ?>" class="flex min-h-11 items-center justify-center rounded-xl text-sm font-semibold <?= $method === $k ? 'bg-white text-ink-950 shadow-soft' : 'text-ink-700 hover:bg-white/60' ?>"<?= $method === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php } ?>
          </nav>

          <?php if ($errors) { ?><p class="alert alert-error mt-5" role="alert">Some details need another look. <?= e(count($errors) === 1 ? 'One field is' : count($errors) . ' fields are') ?> marked below.</p><?php } ?>

          <?php if ($method === 'card') { ?>
            <?= form_open($c, $action, 'mt-5 space-y-4', 'data-processing data-card-form novalidate data-test-cards="' . e(implode(',', array_keys($cards))) . '"') ?>
              <input type="hidden" name="method" value="card">
              <div>
                <label class="label" for="cc-number">Card number</label>
                <div class="relative">
                  <input class="input pr-24 font-mono" id="cc-number" name="cardNumber" inputmode="numeric" autocomplete="off" maxlength="23" placeholder="4343 4343 4343 4345" required data-card-number<?= $aria('cardNumber') ?>>
                  <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs font-semibold uppercase tracking-wide text-ink-600" data-card-brand aria-live="polite"></span>
                </div>
                <?= $err('cardNumber') ?>
              </div>
              <div class="grid grid-cols-2 gap-4">
                <div><label class="label" for="cc-exp">Expiry (MM/YY)</label><input class="input font-mono" id="cc-exp" name="cardExpiry" inputmode="numeric" autocomplete="off" maxlength="5" placeholder="12/30" required data-card-expiry<?= $aria('cardExpiry') ?>><?= $err('cardExpiry') ?></div>
                <div><label class="label" for="cc-cvc">Security code (CVC)</label><input class="input font-mono" id="cc-cvc" name="cardCvc" inputmode="numeric" autocomplete="off" maxlength="3" placeholder="123" required<?= $aria('cardCvc') ?>><?= $err('cardCvc') ?></div>
              </div>
              <div><label class="label" for="cc-name">Name on card</label><input class="input" id="cc-name" name="cardName" autocomplete="off" maxlength="80" value="<?= e($keep['cardName'] ?? '') ?>" required<?= $aria('cardName') ?>><?= $err('cardName') ?></div>
              <button class="btn btn-primary btn-lg w-full"><?= icon('shield', 'size-5') ?> Pay <?= e(php_money((int) $s['amountMinor'])) ?></button>
            </form>
            <details class="mt-5 rounded-2xl border border-ink-200 px-4 py-1">
              <summary class="flex min-h-11 cursor-pointer items-center text-sm font-semibold text-ink-900">Test cards</summary>
              <p class="text-sm text-ink-600">From PayMongo's test mode documentation. Use any future expiry date and any 3-digit code. Any other number is declined.</p>
              <ul class="mt-3 space-y-1.5 pb-3 text-sm">
                <?php foreach ($cards as $num => [$brand, $outcome]) { ?>
                  <li class="flex flex-wrap justify-between gap-x-4"><span class="font-mono text-ink-950"><?= e(trim(chunk_split((string) $num, 4, ' '))) ?></span><span class="text-ink-600"><?= e($brand) ?>: <?= e($outcomeLabel[$outcome]) ?></span></li>
                <?php } ?>
              </ul>
            </details>
          <?php } else { ?>
            <?= form_open($c, $action, 'mt-5 space-y-4', 'data-processing') ?>
              <input type="hidden" name="method" value="<?= e($method) ?>">
              <p class="text-ink-700">You would normally be sent to <?= e($wallet[$method]) ?> to approve this payment. In test mode, enter any Philippine mobile number and choose what <?= e($wallet[$method]) ?> should answer.</p>
              <div><label class="label" for="w-phone"><?= e($wallet[$method]) ?> mobile number</label><input class="input" id="w-phone" name="phone" type="tel" inputmode="tel" autocomplete="off" placeholder="0917 123 4567" value="<?= e($keep['phone'] ?? '') ?>" required<?= $aria('phone') ?>><?= $err('phone') ?></div>
              <div class="flex flex-wrap gap-3"><button class="btn btn-primary btn-lg" name="decision" value="authorize">Authorize test payment</button><button class="btn btn-secondary btn-lg" name="decision" value="fail">Fail test payment</button></div>
            </form>
          <?php } ?>
        <?php } ?>

        <p class="mt-6 border-t border-ink-100 pt-4 text-sm"><a class="link" href="<?= e($action) ?>?cancel=1">Cancel and go back to MA Hotel</a></p>
      </div>
    </section>
  </div>

  <div class="fixed inset-0 z-50 hidden items-center justify-center bg-ink-950/60 p-4" data-processing-panel role="status" aria-live="polite">
    <div class="card card-pad flex max-w-sm flex-col items-center gap-4 text-center">
      <span class="size-10 animate-spin rounded-full border-4 border-ink-200 border-t-clay-500" aria-hidden="true"></span>
      <p class="font-semibold text-ink-950">Processing your test payment</p>
      <p class="text-sm text-ink-600">Please keep this page open.</p>
    </div>
  </div>
</div>
