<?php
/** @var Portal\Web\Ctx $c @var string $title @var string $content @var array $flash @var string $description @var string $mainClass */
$role = $c->role();
$staff = $c->isStaff();
$links = [['/rooms', 'Rooms'], ['/search', 'Book a room']];
if ($role === 'guest') {
    $links = [['/rooms', 'Rooms'], ['/search', 'Book a room'], ['/account', 'My bookings'], ['/account/privacy', 'Privacy']];
} elseif ($staff) {
    $links = [['/staff', 'Dashboard']];
    $byRole = [
        'reception' => [['/staff/reception', 'Front desk'], ['/staff/walk-in', 'Walk-in'], ['/staff/guests', 'Guests'], ['/staff/ota', 'Partners']],
        'housekeeping' => [['/staff/housekeeping', 'Housekeeping'], ['/staff/maintenance', 'Maintenance']],
        'maintenance' => [['/staff/maintenance', 'Maintenance']],
        'cashier' => [['/staff/finance', 'Payments'], ['/staff/reports', 'Reports']],
        'manager' => [['/staff/approvals', 'Approvals'], ['/staff/reception', 'Front desk'], ['/staff/housekeeping', 'Rooms'], ['/staff/finance', 'Finance'], ['/staff/ota', 'Partners'], ['/staff/settings', 'Rates'], ['/staff/reports', 'Reports']],
        'steward' => [['/staff/guests/duplicates', 'Duplicate guests']],
        'it' => [['/staff/admin/users', 'Users'], ['/staff/admin/audit', 'Audit log'], ['/staff/admin/health', 'System health'], ['/staff/admin/privacy', 'Privacy requests'], ['/staff/reports', 'Reports']],
    ];
    $links = array_merge($links, $byRole[$role] ?? []);
}
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
// The landing page draws its header on top of the hero photo.
$onPhoto = $path === '/' && !$staff;
$isActive = fn (array $l): bool => $path === $l[0] || ($l[0] !== '/' && $l[0] !== '/staff' && str_starts_with($path, $l[0] . '/'));
$navLink = fn (array $l, string $cls = 'nav-link'): string => '<a href="' . e($l[0]) . '" class="' . $cls . '"' . ($isActive($l) ? ' aria-current="page"' : '') . '>' . e($l[1]) . '</a>';
$firstName = e(explode(' ', (string) ($c->account()['full_name'] ?? 'Account'))[0]);
$profile = $staff ? '/staff/account' : '/account/profile';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light">
<title><?= e($title !== '' ? $title . ' | MA Hotel' : 'MA Hotel | Boutique hotel in Metro Manila') ?></title>
<meta name="description" content="<?= e($description !== '' ? $description : 'MA Hotel is a boutique hotel in Metro Manila with quiet Standard rooms, Deluxe rooms and Suites, rain showers and a front desk open day and night. Free cancellation up to 48 hours before you arrive.') ?>">
<meta name="theme-color" content="#1f2b4d">
<meta property="og:title" content="MA Hotel">
<meta property="og:description" content="A boutique hotel in Metro Manila with quiet rooms, rain showers and a front desk open day and night.">
<meta property="og:type" content="website">
<link rel="icon" href="/assets/brand/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/assets/brand/favicon-32.png" type="image/png" sizes="32x32">
<link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon-180.png">
<link rel="preload" href="/assets/fonts/inter-latin-var.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/app.css">
<script src="/assets/app.js" defer></script>
</head>
<body class="flex min-h-screen flex-col<?= $staff ? ' staff' : '' ?>">
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded-full focus:bg-white focus:px-4 focus:py-2 focus:text-ink-950 focus:shadow-float">Skip to content</a>

<?php if ($staff) { ?>
<header class="no-print sticky top-0 z-30 border-b border-ink-200/70 bg-white/90 backdrop-blur">
  <div class="flex items-center justify-between gap-3 px-4 py-2.5 sm:px-6">
    <a href="/staff" class="flex shrink-0 items-center gap-3 rounded-lg">
      <img src="/assets/brand/ma-hotel-logo-light.svg" alt="MA Hotel" class="hidden h-8 w-auto sm:block" width="124" height="32">
      <img src="/assets/brand/ma-hotel-mark-light.svg" alt="MA Hotel" class="size-8 sm:hidden" width="32" height="32">
      <span class="hidden border-l border-ink-200 pl-3 text-sm font-medium text-ink-600 md:inline">Staff</span>
    </a>
    <div class="flex items-center gap-1 sm:gap-2">
      <span class="badge badge-slate hidden sm:inline-flex"><?= e(ucfirst($role)) ?></span>
      <a href="<?= e($profile) ?>" class="nav-link"<?= $path === $profile ? ' aria-current="page"' : '' ?>><?= $firstName ?></a>
      <?= form_open($c, '/logout') ?><button class="btn btn-secondary">Sign out</button></form>
    </div>
  </div>
  <nav aria-label="Staff" class="no-scrollbar flex gap-1 overflow-x-auto border-t border-ink-100 px-4 py-2 sm:px-6 xl:hidden">
    <?php foreach ($links as $l) { echo $navLink($l, 'staff-nav-link'); } ?>
  </nav>
</header>
<div class="flex flex-1">
  <aside class="no-print hidden w-60 shrink-0 border-r border-ink-200/70 bg-white/60 xl:block">
    <nav aria-label="Staff sections" class="sticky top-16 flex flex-col gap-1 p-4">
      <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wide text-ink-500">Workspace</p>
      <?php foreach ($links as $l) { echo $navLink($l, 'staff-nav-link'); } ?>
    </nav>
  </aside>
  <div class="flex min-w-0 flex-1 flex-col">
<?php } else { ?>
<header class="no-print <?= $onPhoto ? 'on-photo absolute inset-x-2 top-2 z-30 text-white sm:inset-x-3 sm:top-3' : 'relative z-30' ?>">
  <div class="container-page flex items-center justify-between gap-3 py-4 <?= $onPhoto ? 'sm:py-5' : '' ?>">
    <a href="/" class="flex shrink-0 items-center rounded-lg">
      <img src="/assets/brand/<?= $onPhoto ? 'ma-hotel-logo-dark.svg' : 'ma-hotel-logo-light.svg' ?>" alt="MA Hotel" class="h-9 w-auto" width="140" height="36">
    </a>
    <nav aria-label="Main" class="hidden items-center gap-1 lg:flex">
      <?php foreach ($links as $l) { echo $navLink($l); } ?>
    </nav>
    <div class="hidden items-center gap-2 lg:flex">
      <?php if ($c->user() === null) { ?>
        <a href="/login" class="nav-link"<?= $path === '/login' ? ' aria-current="page"' : '' ?>>Sign in</a>
        <a href="/register" class="btn btn-sm <?= $onPhoto ? 'btn-light' : 'btn-dark' ?>">Create account</a>
      <?php } else { ?>
        <a href="<?= e($profile) ?>" class="nav-link"<?= $path === $profile ? ' aria-current="page"' : '' ?>><?= $firstName ?></a>
        <?= form_open($c, '/logout') ?><button class="btn btn-sm <?= $onPhoto ? 'btn-outline-light' : 'btn-secondary' ?>">Sign out</button></form>
      <?php } ?>
    </div>
    <details class="relative lg:hidden">
      <summary class="flex size-11 cursor-pointer list-none items-center justify-center rounded-full <?= $onPhoto ? 'bg-white/15 text-white hover:bg-white/25' : 'bg-white text-ink-900 ring-1 ring-ink-200 hover:bg-ink-50' ?>" aria-label="Menu"><?= icon('menu', 'size-6') ?></summary>
      <div class="absolute right-0 z-40 mt-2 w-72 rounded-3xl bg-white p-2 text-ink-900 shadow-float ring-1 ring-black/5">
        <nav aria-label="Mobile" class="flex flex-col gap-1">
          <?php foreach ($links as $l) { echo $navLink($l, 'nav-link block rounded-2xl px-4 py-3 text-base'); } ?>
          <div class="my-1 h-px bg-ink-100"></div>
          <?php if ($c->user() === null) { ?>
            <a href="/login" class="nav-link block rounded-2xl px-4 py-3 text-base">Sign in</a>
            <a href="/register" class="btn btn-dark m-1">Create account</a>
          <?php } else { ?>
            <a href="<?= e($profile) ?>" class="nav-link block rounded-2xl px-4 py-3 text-base">My profile</a>
            <?= form_open($c, '/logout', 'm-1') ?><button class="btn btn-secondary w-full">Sign out</button></form>
          <?php } ?>
        </nav>
      </div>
    </details>
  </div>
</header>
<?php } ?>

<?php if ($flash) { ?>
<div id="flash" class="<?= $staff ? 'px-4 pt-4 sm:px-6 lg:px-8' : 'container-page mt-2' ?> space-y-2" role="status" aria-live="polite">
  <?php foreach ($flash as [$type, $msg]) { ?>
    <p class="alert <?= $type === 'error' ? 'alert-error' : 'alert-ok' ?>"><?= e($msg) ?></p>
  <?php } ?>
</div>
<?php } ?>

<main id="main" class="flex-1 <?= e($mainClass) ?>" tabindex="-1">
<?= $content ?>
</main>

<?php if ($staff) { ?>
<footer class="no-print border-t border-ink-200/70 px-4 py-4 text-xs text-ink-600 sm:px-6 lg:px-8">
  MA Hotel staff workspace. Your role only shows the tools you need. Every action is recorded in the audit log.
</footer>
  </div>
</div>
<?php } else { ?>
<footer class="no-print mx-2 mb-2 mt-16 rounded-[2rem] bg-ink-950 text-ink-300 sm:mx-3 sm:mb-3 sm:mt-24">
  <div class="container-page grid gap-10 py-12 text-sm sm:py-16 md:grid-cols-12">
    <div class="md:col-span-5">
      <img src="/assets/brand/ma-hotel-logo-dark.svg" alt="MA Hotel" class="h-10 w-auto" width="155" height="40">
      <p class="mt-5 max-w-sm text-pretty text-base text-ink-300">A 45-room boutique hotel in Metro Manila. Twenty Standard rooms, fifteen Deluxe rooms and ten Suites.</p>
    </div>
    <div class="md:col-span-3">
      <p class="font-semibold text-white">Your stay</p>
      <ul class="mt-3 space-y-2">
        <li>Check-in from 2:00 PM</li>
        <li>Check-out by 12:00 noon</li>
        <li>Free cancellation until 48 hours before arrival</li>
      </ul>
    </div>
    <div class="md:col-span-4">
      <p class="font-semibold text-white">Explore</p>
      <ul class="mt-3 space-y-2">
        <li><a class="hover:text-white hover:underline" href="/rooms">Rooms and suites</a></li>
        <li><a class="hover:text-white hover:underline" href="/search">Book a room</a></li>
        <li><a class="hover:text-white hover:underline" href="<?= $role === 'guest' ? '/account/privacy' : '/login?next=/account/privacy' ?>">Your data and privacy</a></li>
      </ul>
    </div>
  </div>
  <p class="pointer-events-none select-none overflow-hidden whitespace-nowrap px-4 text-center font-display text-[18vw] font-semibold leading-[0.8] tracking-tighter text-white/90 sm:px-6" aria-hidden="true">MA HOTEL</p>
  <div class="container-page flex flex-wrap justify-between gap-2 border-t border-white/10 py-6 text-xs text-ink-400">
    <p>MA Hotel, Metro Manila, Philippines</p>
    <p>We accept card, GCash and Maya.</p>
  </div>
</footer>
<?php } ?>
</body>
</html>
