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
$showNav = $staff ? 'hidden items-center gap-1 xl:flex' : 'hidden items-center gap-1 lg:flex';
$showActions = $staff ? 'hidden items-center gap-2 xl:flex' : 'hidden items-center gap-2 lg:flex';
$hideMenu = $staff ? 'relative xl:hidden' : 'relative lg:hidden';
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$navLink = function (array $l, string $extra = '') use ($path): string {
    $active = $path === $l[0] || ($l[0] !== '/' && $l[0] !== '/staff' && str_starts_with($path, $l[0] . '/'));
    return '<a href="' . e($l[0]) . '" class="whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium ' . ($active ? 'bg-brand-800 text-white' : 'text-brand-50 hover:bg-brand-800') . ' ' . $extra . '"' . ($active ? ' aria-current="page"' : '') . '>' . e($l[1]) . '</a>';
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title !== '' ? $title . ' | HarborLight Hotels' : 'HarborLight Hotels & Resorts, Metro Manila') ?></title>
<meta name="description" content="<?= e($description !== '' ? $description : 'A 45-room boutique hotel in Metro Manila. Book direct for the best rate, free cancellation up to 48 hours before arrival.') ?>">
<meta name="theme-color" content="#0c4a6e">
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/app.css">
<script src="/assets/app.js" defer></script>
</head>
<body class="flex min-h-screen flex-col">
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-brand-900">Skip to content</a>

<header class="no-print bg-brand-900 text-white">
  <div class="container-page flex items-center justify-between gap-3 py-3">
    <a href="/" class="flex items-center gap-2 whitespace-nowrap text-lg font-bold tracking-tight text-white">
      <img src="/assets/img/logo.svg" alt="" class="size-8" width="32" height="32">
      <span>HarborLight <span class="<?= $staff ? 'hidden font-normal text-brand-200 2xl:inline' : 'hidden font-normal text-brand-200 md:inline' ?>">Hotels &amp; Resorts</span></span>
    </a>
    <nav aria-label="Main" class="<?= $showNav ?>">
      <?php foreach ($links as $l) { echo $navLink($l); } ?>
    </nav>
    <div class="<?= $showActions ?>">
      <?php if ($c->user() === null) { ?>
        <a href="/login" class="rounded-md px-3 py-2 text-sm font-medium text-brand-50 hover:bg-brand-800">Sign in</a>
        <a href="/register" class="btn btn-sm bg-lantern-400 text-slate-900 hover:bg-lantern-500">Create account</a>
      <?php } else { ?>
        <?php if ($staff) { ?><span class="badge badge-blue"><?= e(ucfirst($role)) ?></span><?php } ?>
        <a href="<?= $staff ? '/staff/account' : '/account/profile' ?>" class="rounded-md px-3 py-2 text-sm font-medium text-brand-50 hover:bg-brand-800"><?= e(explode(' ', (string) ($c->account()['full_name'] ?? 'Account'))[0]) ?></a>
        <?= form_open($c, '/logout') ?><button class="whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium text-brand-50 hover:bg-brand-800">Sign out</button></form>
      <?php } ?>
    </div>
    <details class="<?= $hideMenu ?>">
      <summary class="flex size-11 cursor-pointer list-none items-center justify-center rounded-md hover:bg-brand-800" aria-label="Menu"><?= icon('menu', 'size-6') ?></summary>
      <div class="absolute right-0 z-40 mt-2 w-64 rounded-xl bg-brand-900 p-2 shadow-xl ring-1 ring-brand-700">
        <nav aria-label="Mobile" class="flex flex-col gap-1">
          <?php foreach ($links as $l) { echo $navLink($l, 'block'); } ?>
          <?php if ($c->user() === null) { ?>
            <a href="/login" class="block rounded-md px-3 py-2 text-sm font-medium text-brand-50 hover:bg-brand-800">Sign in</a>
            <a href="/register" class="block rounded-md px-3 py-2 text-sm font-medium text-lantern-400 hover:bg-brand-800">Create account</a>
          <?php } else { ?>
            <a href="<?= $staff ? '/staff/account' : '/account/profile' ?>" class="block rounded-md px-3 py-2 text-sm font-medium text-brand-50 hover:bg-brand-800">My profile</a>
            <?= form_open($c, '/logout') ?><button class="block w-full rounded-md px-3 py-2 text-left text-sm font-medium text-brand-50 hover:bg-brand-800">Sign out</button></form>
          <?php } ?>
        </nav>
      </div>
    </details>
  </div>
</header>

<?php if ($flash) { ?>
<div id="flash" class="container-page mt-4 space-y-2" role="status" aria-live="polite">
  <?php foreach ($flash as [$type, $msg]) { ?>
    <p class="alert <?= $type === 'error' ? 'alert-error' : 'alert-ok' ?>"><?= e($msg) ?></p>
  <?php } ?>
</div>
<?php } ?>

<main id="main" class="flex-1 <?= e($mainClass) ?>" tabindex="-1">
<?= $content ?>
</main>

<footer class="no-print mt-12 bg-brand-950 text-brand-100">
  <div class="container-page grid gap-6 py-8 text-sm sm:grid-cols-3">
    <div><p class="font-semibold text-white">HarborLight Hotels &amp; Resorts</p><p class="mt-1">45 rooms and suites in Metro Manila.<br>Check-in from 2:00 PM, check-out by 12:00 noon.</p></div>
    <div><p class="font-semibold text-white">Book direct</p><p class="mt-1">Free cancellation until 48 hours before arrival. Pay securely online or ask about paying at the hotel.</p></div>
    <div><p class="font-semibold text-white">Your data</p><p class="mt-1">We keep only what we need to host you. You can ask to see, correct or delete it in <a class="underline" href="<?= $role === 'guest' ? '/account/privacy' : '/login?next=/account/privacy' ?>">your privacy page</a>.</p></div>
  </div>
</footer>
</body>
</html>
