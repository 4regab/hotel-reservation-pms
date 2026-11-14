<?php /** @var Portal\Web\Ctx $c @var array $r @var ?array $folio @var ?array $stay @var array $ready @var ?array $override @var array $cancel @var bool $noShowDue @var string $startedAt */
$role = $c->role(); $id = $r['reservationId']; $today = today();
$isFront = in_array($role, ['reception', 'manager'], true); ?>
<div class="container-page max-w-5xl pb-4 pt-8 sm:pt-10">
  <nav aria-label="Breadcrumb" class="text-sm text-ink-600"><a class="link" href="/staff/reception">Front desk</a> <span aria-hidden="true">/</span> <?= e($r['code']) ?></nav>
  <h1 class="page-title mt-2">Booking <?= e($r['code']) ?> <?= badge($r['status']) ?></h1>

  <div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
      <?php include __DIR__ . '/../partials/booking_summary.php'; ?>
      <section class="card card-pad" aria-labelledby="g-h">
        <h2 id="g-h" class="text-lg font-semibold">Guest</h2>
        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
          <div><dt class="text-ink-600">Name</dt><dd class="font-medium"><?= e($r['guestName']) ?></dd></div>
          <div><dt class="text-ink-600">Source</dt><dd><?= badge($r['source']) ?></dd></div>
          <?php if (isset($r['guestEmail'])) { ?><div><dt class="text-ink-600">Email</dt><dd class="break-all"><?= e($r['guestEmail']) ?></dd></div><div><dt class="text-ink-600">Phone</dt><dd><?= e($r['guestPhone']) ?></dd></div><?php } ?>
          <?php if ($stay) { ?><div><dt class="text-ink-600">Checked in</dt><dd><?= e(dt($stay['checkedInAt'])) ?> with <?= e(str_replace('_', ' ', strtolower($stay['identityDocType']))) ?></dd></div>
            <div><dt class="text-ink-600">Room</dt><dd class="font-semibold"><?= e(implode(', ', array_column($stay['rooms'], 'roomNumber'))) ?></dd></div>
            <?php if ($stay['earlyOverride']) { ?><div class="sm:col-span-2"><dt class="text-ink-600">Early arrival approved</dt><dd><?= e($stay['earlyOverride']) ?></dd></div><?php } ?><?php } ?>
        </dl>
      </section>

      <?php if ($folio) { ?>
        <section class="card card-pad" aria-labelledby="f-h">
          <h2 id="f-h" class="text-lg font-semibold">Bill <?= badge($folio['folioStatus'] ?? 'OPEN') ?></h2>
          <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            <div><dt class="text-ink-600">Total</dt><dd class="font-semibold"><?= e(php_money($folio['totalMinor'])) ?></dd></div>
            <div><dt class="text-ink-600">Paid</dt><dd class="font-semibold"><?= e(php_money($folio['paidMinor'])) ?></dd></div>
            <div><dt class="text-ink-600">Refunded</dt><dd class="font-semibold"><?= e(php_money($folio['refundedMinor'])) ?></dd></div>
            <div><dt class="text-ink-600">Balance</dt><dd class="text-lg font-bold <?= $folio['balanceMinor'] > 0 ? 'text-red-800' : 'text-emerald-800' ?>"><?= e(php_money($folio['balanceMinor'])) ?></dd></div>
          </dl>
          <?php if (($folio['folioOpen'] ?? false) && in_array($role, ['cashier', 'manager'], true)) { ?>
            <?= form_open($c, '/staff/reservations/' . $id . '/charge', 'mt-4 grid gap-3 sm:grid-cols-[1fr_10rem_auto] sm:items-end') ?>
              <?= field('Add a charge', 'description', 'text', '', 'required maxlength="120" placeholder="Minibar, laundry, parking"') ?>
              <?= field('Amount (PHP)', 'amount', 'text', '', 'required inputmode="decimal" placeholder="850.00"') ?>
              <button class="btn btn-secondary">Add charge</button>
            </form>
          <?php } ?>
        </section>
      <?php } ?>
    </div>

    <div class="space-y-6">
      <?php if ($r['status'] === 'HELD' && $isFront) { ?>
        <section class="card card-pad" aria-labelledby="h-h"><h2 id="h-h" class="text-lg font-semibold">Hold (10 minutes)</h2>
          <p class="mt-1 text-sm text-ink-600">Expires <?= e(dt($r['holdExpiresAt'])) ?>.</p>
          <?= form_open($c, '/staff/reservations/' . $id . '/onsite-payment', 'mt-3 space-y-3') ?>
            <?= select_field('Payment taken at the desk', 'method', ['CASH' => 'Cash', 'CARD_TERMINAL' => 'Card terminal', 'GCASH' => 'GCash'], 'CASH') ?>
            <button class="btn btn-primary w-full">Record <?= e(php_money($r['amountMinor'])) ?> payment</button>
          </form>
          <?php if ($r['guaranteeRequested']) { ?><p class="alert alert-info mt-3 text-sm">Pay-at-hotel is waiting for the manager.</p>
            <?php if ($role === 'manager') { echo form_open($c, '/staff/reservations/' . $id . '/approve-guarantee', 'mt-2') . '<button class="btn btn-secondary w-full">Approve pay-at-hotel</button></form>'; } ?>
          <?php } else { echo form_open($c, '/staff/reservations/' . $id . '/request-guarantee', 'mt-3') . '<button class="btn btn-secondary w-full">Ask manager: pay at hotel</button></form>'; } ?>
        </section>
      <?php } ?>

      <?php if ($r['status'] === 'CONFIRMED' && $isFront) { ?>
        <section class="card card-pad" aria-labelledby="ci-h"><h2 id="ci-h" class="text-lg font-semibold">Check in</h2>
          <?php $early = $r['checkIn'] > $today; $approved = $override && $override['status'] === 'APPROVED'; ?>
          <?php if ($early && !$approved) { ?>
            <p class="alert alert-warn mt-2 text-sm">Arrival is <?= e(d($r['checkIn'])) ?>. Check-in before that date needs the manager.</p>
            <?php if ($override && $override['status'] === 'PENDING') { ?><p class="mt-2 text-sm">Request sent: <?= e($override['reason']) ?>. Waiting for the manager.</p>
            <?php } elseif ($override && $override['status'] === 'REJECTED') { ?><p class="mt-2 text-sm font-medium text-red-800">The manager declined early arrival.</p><?php } ?>
            <?php if (!$override || $override['status'] === 'REJECTED') { echo form_open($c, '/staff/reservations/' . $id . '/request-override', 'mt-3 space-y-3') . textarea_field('Why does the guest need to arrive early?', 'reason', '', 'required maxlength="200"') . '<button class="btn btn-secondary w-full">Ask manager</button></form>'; } ?>
          <?php } ?>
          <?php if (!$early || $approved || $role === 'manager') { ?>
            <?= form_open($c, '/staff/reservations/' . $id . '/check-in', 'mt-3 space-y-3') ?>
              <input type="hidden" name="queueStartedAt" value="<?= e($startedAt) ?>">
              <?= select_field('Photo ID shown', 'identityDocType', ['PASSPORT' => 'Passport', 'DRIVERS_LICENSE' => "Driver's license", 'NATIONAL_ID' => 'National ID', 'OTHER' => 'Other government ID'], 'NATIONAL_ID') ?>
              <p class="hint">Only the type of ID is saved. Never write down the number.</p>
              <?= checkbox_field('I checked the ID and it matches the guest', 'identityVerified', false, 'required') ?>
              <fieldset><legend class="label">Room<?= $r['quantity'] > 1 ? 's (pick ' . e($r['quantity']) . ')' : '' ?></legend>
                <?php if ($ready) { ?><div class="grid grid-cols-3 gap-2"><?php foreach ($ready as $i => $rm) { ?>
                  <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-ink-300 px-2 text-sm"><input type="<?= $r['quantity'] > 1 ? 'checkbox' : 'radio' ?>" name="roomIds[]" value="<?= e($rm['roomId']) ?>" <?= $i < (int) $r['quantity'] && $r['quantity'] == 1 && $i === 0 ? 'checked' : '' ?>> <span><strong><?= e($rm['roomNumber']) ?></strong><span class="block text-xs text-ink-600"><?= e($rm['view']) ?></span></span></label>
                <?php } ?></div><p class="hint">Leave all unticked to let the system pick.</p>
                <?php } else { ?><p class="alert alert-warn text-sm">No ready room of this type right now. Ask housekeeping.</p><?php } ?></fieldset>
              <?php if ($early && !$approved && $role === 'manager') { echo textarea_field('Manager reason for early check-in', 'overrideReason', '', 'required maxlength="200"'); } ?>
              <button class="btn btn-primary w-full">Check in</button>
            </form>
          <?php } ?>
        </section>
      <?php } ?>

      <?php if ($r['status'] === 'CHECKED_IN' && $isFront) { ?>
        <section class="card card-pad" aria-labelledby="co-h"><h2 id="co-h" class="text-lg font-semibold">Check out</h2>
          <?php if ($folio && $folio['balanceMinor'] > 0) { ?><p class="alert alert-warn mt-2 text-sm">Balance due: <strong><?= e(php_money($folio['balanceMinor'])) ?></strong>. Take it now to finish check-out.</p><?php } ?>
          <?= form_open($c, '/staff/reservations/' . $id . '/check-out', 'mt-3 space-y-3') ?>
            <?php if ($folio && $folio['balanceMinor'] > 0) { echo select_field('Paid by', 'method', ['CASH' => 'Cash', 'CARD_TERMINAL' => 'Card terminal', 'GCASH' => 'GCash'], 'CASH') . checkbox_field('I collected ' . php_money($folio['balanceMinor']) . ' from the guest', 'settleOnSite', false, 'required'); } ?>
            <button class="btn btn-primary w-full">Check out</button>
          </form>
        </section>
      <?php } ?>

      <?php if ($isFront && ($cancel['allowed'] || $noShowDue)) { ?>
        <section class="card card-pad" aria-labelledby="x-h"><h2 id="x-h" class="text-lg font-semibold">Cancel or no-show</h2>
          <?php if ($cancel['allowed']) { ?>
            <p class="mt-2 text-sm text-ink-700"><?= $r['status'] === 'HELD' ? 'No charge for a hold.' : ($cancel['free'] ? 'Free cancellation still applies.' : 'Free cancellation ended. Penalty: ' . e(php_money($cancel['penaltyMinor'])) . '.') ?></p>
            <?= form_open($c, '/staff/reservations/' . $id . '/cancel', 'mt-3 space-y-3', 'data-confirm="Cancel booking ' . e($r['code']) . '?"') ?>
              <?php if ($role === 'manager' && !$cancel['free'] && $r['status'] === 'CONFIRMED') { echo checkbox_field('Waive the penalty (manager)', 'waive'); } ?>
              <button class="btn btn-danger w-full">Cancel booking</button>
            </form>
          <?php } ?>
          <?php if ($noShowDue) { ?>
            <?= form_open($c, '/staff/reservations/' . $id . '/no-show', 'mt-3 space-y-3', 'data-confirm="Record a no-show for ' . e($r['code']) . '? The first night is kept."') ?>
              <?php if ($role === 'manager') { echo checkbox_field('Waive the first-night charge (manager)', 'waive'); } ?>
              <button class="btn btn-secondary w-full">Mark as no-show</button>
            </form>
          <?php } ?>
        </section>
      <?php } ?>
    </div>
  </div>
</div>
