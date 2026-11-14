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

<?php if ($staff) {
    // One outline icon per staff area, so the sidebar can be scanned at a glance.
    $navIcon = ['/staff' => 'home', '/staff/reception' => 'desk', '/staff/walk-in' => 'user', '/staff/guests' => 'users', '/staff/ota' => 'globe', '/staff/housekeeping' => 'bed', '/staff/maintenance' => 'wrench',
        '/staff/finance' => 'cash', '/staff/reports' => 'chart', '/staff/approvals' => 'check', '/staff/settings' => 'sliders', '/staff/guests/duplicates' => 'users', '/staff/admin/users' => 'users',
        '/staff/admin/audit' => 'list', '/staff/admin/health' => 'pulse', '/staff/admin/privacy' => 'shield'];
    $sideLink = fn (array $l): string => '<a href="' . e($l[0]) . '" class="staff-nav-link"' . ($isActive($l) ? ' aria-current="page"' : '') . '>' . icon($navIcon[$l[0]] ?? 'list', 'size-[1.125rem] shrink-0') . e($l[1]) . '</a>';
    $roleName = ['reception' => 'Reception', 'housekeeping' => 'Housekeeping', 'maintenance' => 'Maintenance', 'cashier' => 'Cashier', 'manager' => 'Manager', 'it' => 'IT and privacy', 'steward' => 'Data steward'][$role] ?? ucfirst($role);
?>
<header class="no-print sticky top-0 z-30 border-b border-ink-200 bg-white xl:hidden">
  <div class="flex h-14 items-center justify-between gap-3 px-4 sm:px-6">
    <a href="/staff" class="flex min-h-11 shrink-0 items-center gap-3 rounded-lg">
      <img src="/assets/brand/ma-hotel-logo-light.svg" alt="MA Hotel" class="hidden h-7 w-auto sm:block" width="109" height="28">
      <img src="/assets/brand/ma-hotel-mark-light.svg" alt="MA Hotel" class="size-8 sm:hidden" width="32" height="32">
    </a>
    <div class="flex items-center gap-2">
      <span class="badge badge-slate hidden sm:inline-flex"><?= e($roleName) ?></span>
      <a href="<?= e($profile) ?>" class="nav-link"<?= $path === $profile ? ' aria-current="page"' : '' ?>><?= $firstName ?></a>
      <?= form_open($c, '/logout') ?><button class="btn btn-secondary">Sign out</button></form>
    </div>
  </div>
  <nav aria-label="Staff" class="no-scrollbar flex gap-1 overflow-x-auto border-t border-ink-100 px-4 py-2 sm:px-6">
    <?php foreach ($links as $l) { echo $sideLink($l); } ?>
  </nav>
</header>
<div class="flex flex-1">
  <aside class="no-print fixed inset-y-0 left-0 z-30 hidden w-64 flex-col border-r border-ink-200 bg-white xl:flex">
    <a href="/staff" class="flex h-16 shrink-0 items-center gap-3 border-b border-ink-100 px-5">
      <img src="/assets/brand/ma-hotel-logo-light.svg" alt="MA Hotel" class="h-7 w-auto" width="109" height="28">
      <span class="text-xs font-medium text-ink-600">Staff</span>
    </a>
    <nav aria-label="Staff sections" class="flex flex-1 flex-col gap-0.5 overflow-y-auto p-3">
      <?php foreach ($links as $l) { echo $sideLink($l); } ?>
    </nav>
    <div class="flex items-center gap-3 border-t border-ink-100 p-4">
      <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-ink-900 text-sm font-semibold text-white" aria-hidden="true"><?= e(preg_split('//u', html_entity_decode($firstName), 2, PREG_SPLIT_NO_EMPTY)[0] ?? '') ?></span>
      <a href="<?= e($profile) ?>" class="min-w-0 flex-1 rounded-lg"><span class="block truncate text-sm font-medium text-ink-950"><?= $firstName ?></span><span class="block text-xs text-ink-600"><?= e($roleName) ?></span></a>
      <?= form_open($c, '/logout') ?><button class="btn btn-ghost px-2.5" aria-label="Sign out" title="Sign out"><?= icon('logout', 'size-[1.125rem]') ?></button></form>
    </div>
  </aside>
  <div class="flex min-w-0 flex-1 flex-col xl:pl-64">
<?php } else { ?>
<header class="no-print <?= $onPhoto ? 'on-photo absolute inset-x-0 top-0 z-30 text-white' : 'relative z-30' ?>">
  <div class="container-page flex items-center justify-between gap-3 py-4 <?= $onPhoto ? 'sm:py-5' : '' ?>">
    <a href="/" class="flex min-h-11 shrink-0 items-center rounded-lg">
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
    <details class="relative lg:hidden" data-menu>
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
      <ul class="mt-2">
        <li><a class="inline-flex min-h-11 items-center hover:text-white hover:underline" href="/rooms">Rooms and suites</a></li>
        <li><a class="inline-flex min-h-11 items-center hover:text-white hover:underline" href="/search">Book a room</a></li>
        <li><a class="inline-flex min-h-11 items-center hover:text-white hover:underline" href="<?= $role === 'guest' ? '/account/privacy' : '/login?next=/account/privacy' ?>">Your data and privacy</a></li>
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
