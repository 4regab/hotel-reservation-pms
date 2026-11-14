<?php /** @var Portal\Web\Ctx $c @var string $title @var string $content @var array $flash */ ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light">
<meta name="robots" content="noindex">
<title><?= e($title) ?> | MA Hotel</title>
<link rel="icon" href="/assets/brand/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/app.css">
<script src="/assets/app.js" defer></script>
</head>
<body class="flex min-h-screen flex-col bg-ink-50">
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded-full focus:bg-white focus:px-4 focus:py-2 focus:text-ink-950">Skip to content</a>
<header class="border-b border-ink-200 bg-white">
  <div class="mx-auto flex w-full max-w-5xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
    <span class="flex items-center gap-3"><img src="/assets/brand/ma-hotel-logo-light.svg" alt="MA Hotel" class="h-8 w-auto" width="124" height="32"><span class="hidden border-l border-ink-200 pl-3 text-sm font-medium text-ink-600 sm:inline">Secure checkout</span></span>
    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-3 py-1 text-xs font-bold uppercase tracking-wide text-amber-950"><?= icon('alert', 'size-4') ?> Test mode</span>
  </div>
</header>
<?php if ($flash) { ?>
<div class="mx-auto mt-4 w-full max-w-5xl space-y-2 px-4 sm:px-6" role="status" aria-live="polite"><?php foreach ($flash as [$type, $msg]) { ?><p class="alert <?= $type === 'error' ? 'alert-error' : 'alert-ok' ?>"><?= e($msg) ?></p><?php } ?></div>
<?php } ?>
<main id="main" class="flex-1" tabindex="-1">
<?= $content ?>
</main>
<footer class="px-4 py-6 text-center text-xs text-ink-600">
  <p class="flex items-center justify-center gap-1.5"><?= icon('shield', 'size-4') ?> Simulated PayMongo test mode. No real card network is contacted and no money moves.</p>
</footer>
</body>
</html>
