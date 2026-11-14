<?php /** @var array $r */ ?>
<header class="flex items-center justify-between border-b border-slate-300 pb-4">
  <div class="flex items-center gap-3"><img src="/assets/img/logo.svg" alt="" class="size-10" width="40" height="40"><div><p class="text-xl font-bold">HarborLight Hotels &amp; Resorts</p><p class="text-sm text-slate-600">Metro Manila, Philippines</p></div></div>
  <button class="btn btn-secondary no-print" type="button" data-print><?= icon('print', 'size-5') ?> Print</button>
</header>
<h1 class="mt-6 text-2xl font-bold">Booking confirmation</h1>
<p class="mt-1 text-slate-700">Show this page or your booking code at the front desk. Please bring a valid photo ID.</p>
<?php include __DIR__ . '/partials/booking_summary.php'; ?>
<section class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
  <div><h2 class="font-semibold">Arrival</h2><p class="mt-1 text-slate-700">Check-in is from 2:00 PM. Early check-in is subject to room availability. The front desk is open all day and night.</p></div>
  <div><h2 class="font-semibold">Changes and cancellation</h2><p class="mt-1 text-slate-700">Sign in to HarborLight to cancel. Free until <?= e($r['freeCancelHours']) ?> hours before arrival, then the first night is charged.</p></div>
</section>
<p class="mt-8 border-t border-slate-200 pt-3 text-xs text-slate-600">Issued <?= e(dt(gmdate('Y-m-d H:i:s'))) ?> Manila time.</p>
