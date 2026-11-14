<?php /** @var array $r */ ?>
<header class="flex items-center justify-between border-b border-ink-300 pb-4">
  <div class="flex items-center gap-3"><span class="block h-10 [&>svg]:h-full [&>svg]:w-auto"><?= file_get_contents(dirname(__DIR__) . '/public/assets/brand/ma-hotel-logo-light.svg') /* inline so the downloaded file keeps its logo */ ?></span><div class="border-l border-ink-200 pl-3"><p class="sr-only">MA Hotel</p><p class="text-sm text-ink-600">Metro Manila, Philippines</p></div></div>
  <button class="btn btn-secondary no-print" type="button" data-print><?= icon('print', 'size-5') ?> Print</button>
</header>
<h1 class="page-title mt-8 text-3xl sm:text-4xl">Booking confirmation</h1>
<p class="mt-1 text-ink-700">Show this page or your booking code at the front desk. Please bring a valid photo ID.</p>
<?php include __DIR__ . '/partials/booking_summary.php'; ?>
<section class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
  <div><h2 class="font-semibold">Arrival</h2><p class="mt-1 text-ink-700">Check-in is from 2:00 PM. Early check-in is subject to room availability. The front desk is open all day and night.</p></div>
  <div><h2 class="font-semibold">Changes and cancellation</h2><p class="mt-1 text-ink-700">Sign in to your MA Hotel account to cancel. Free until <?= e($r['freeCancelHours']) ?> hours before arrival, then the first night is charged.</p></div>
</section>
<p class="mt-8 border-t border-ink-200 pt-3 text-xs text-ink-600">Issued <?= e(dt(gmdate('Y-m-d H:i:s'))) ?> Manila time.</p>
