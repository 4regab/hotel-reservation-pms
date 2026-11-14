<?php
declare(strict_types=1);

// Template helpers (plain functions so views stay short). Loaded once by View.
use Portal\Fmt;
use Portal\Web\Ctx;

function e(mixed $s): string
{
    return Fmt::e($s === null ? '' : (string) $s);
}

function php_money(int $minor): string
{
    return Fmt::php($minor);
}

function d(string $ymd): string
{
    return Fmt::date($ymd);
}

function dt(?string $utc): string
{
    return Fmt::dateTime($utc);
}

function csrf_field(Ctx $c): string
{
    return '<input type="hidden" name="_csrf" value="' . e($c->csrf()) . '">';
}

/** A POST form opener with the CSRF field. Close with </form>. */
function form_open(Ctx $c, string $action, string $class = '', string $extra = ''): string
{
    return '<form method="post" action="' . e($action) . '"' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ($extra !== '' ? ' ' . $extra : '') . '>' . csrf_field($c);
}

/** @return array{0:string,1:string} badge class and label for a status word */
function badge_for(string $status): array
{
    $map = [
        'CONFIRMED' => ['badge-green', 'Confirmed'], 'CHECKED_IN' => ['badge-blue', 'In house'], 'CHECKED_OUT' => ['badge-slate', 'Checked out'],
        'HELD' => ['badge-amber', 'On hold'], 'EXPIRED' => ['badge-slate', 'Expired'], 'CANCELLED' => ['badge-red', 'Cancelled'], 'NO_SHOW' => ['badge-red', 'No-show'],
        'READY' => ['badge-green', 'Ready'], 'OCCUPIED' => ['badge-blue', 'Occupied'], 'DIRTY' => ['badge-amber', 'Dirty'], 'CLEANING' => ['badge-blue', 'Cleaning'], 'OUT_OF_SERVICE' => ['badge-red', 'Out of service'],
        'OPEN' => ['badge-amber', 'Open'], 'IN_PROGRESS' => ['badge-blue', 'In progress'], 'CLEANED' => ['badge-blue', 'Awaiting inspection'], 'DONE' => ['badge-green', 'Done'],
        'PENDING' => ['badge-amber', 'Pending'], 'APPROVED' => ['badge-green', 'Approved'], 'REJECTED' => ['badge-red', 'Rejected'], 'CONFLICT' => ['badge-red', 'Conflict'], 'RETURNED' => ['badge-slate', 'Returned'],
        'REQUESTED' => ['badge-amber', 'Requested'], 'COMPLETED' => ['badge-green', 'Completed'], 'CAPTURED' => ['badge-green', 'Paid'], 'REVIEW' => ['badge-red', 'Needs review'],
        'IN_REVIEW' => ['badge-blue', 'In review'], 'SENT' => ['badge-green', 'Sent'], 'LOGGED' => ['badge-slate', 'Logged'], 'FAILED' => ['badge-red', 'Failed'],
        'PAID' => ['badge-green', 'Paid'], 'PAY_AT_HOTEL' => ['badge-blue', 'Pay at hotel'], 'OTA_PREPAID' => ['badge-blue', 'Paid by partner'], 'NONE' => ['badge-slate', 'Not paid'],
        'DIRECT' => ['badge-slate', 'Website'], 'WALK_IN' => ['badge-slate', 'Walk-in'], 'OTA' => ['badge-blue', 'Travel partner'],
    ];
    return $map[$status] ?? ['badge-slate', ucwords(strtolower(str_replace('_', ' ', $status)))];
}

function badge(string $status): string
{
    [$cls, $label] = badge_for($status);
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

/** Labelled text field. $extra is raw attribute text, for example 'required autocomplete="email"'. */
function field(string $label, string $name, string $type = 'text', string $value = '', string $extra = '', string $hint = ''): string
{
    $id = 'f-' . preg_replace('/[^a-z0-9]+/i', '-', $name);
    return '<div><label class="label" for="' . e($id) . '">' . e($label) . '</label><input class="input" id="' . e($id) . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e($value) . '" ' . $extra
        . ($hint !== '' ? ' aria-describedby="' . e($id) . '-hint"' : '') . '>' . ($hint !== '' ? '<p class="hint" id="' . e($id) . '-hint">' . e($hint) . '</p>' : '') . '</div>';
}

/** @param array<string,string> $options value => label */
function select_field(string $label, string $name, array $options, string $selected = '', string $extra = ''): string
{
    $id = 'f-' . preg_replace('/[^a-z0-9]+/i', '-', $name);
    $o = '';
    foreach ($options as $v => $l) {
        $o .= '<option value="' . e((string) $v) . '"' . ((string) $v === $selected ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return '<div><label class="label" for="' . e($id) . '">' . e($label) . '</label><select class="input" id="' . e($id) . '" name="' . e($name) . '" ' . $extra . '>' . $o . '</select></div>';
}

function textarea_field(string $label, string $name, string $value = '', string $extra = '', string $hint = ''): string
{
    $id = 'f-' . preg_replace('/[^a-z0-9]+/i', '-', $name);
    return '<div><label class="label" for="' . e($id) . '">' . e($label) . '</label><textarea class="input" id="' . e($id) . '" name="' . e($name) . '" rows="3" ' . $extra . '>' . e($value) . '</textarea>'
        . ($hint !== '' ? '<p class="hint">' . e($hint) . '</p>' : '') . '</div>';
}

function checkbox_field(string $label, string $name, bool $checked = false, string $extra = ''): string
{
    $id = 'f-' . preg_replace('/[^a-z0-9]+/i', '-', $name);
    return '<div class="flex items-start gap-3"><input class="mt-1 size-5 rounded border-slate-400 text-brand-700" type="checkbox" id="' . e($id) . '" name="' . e($name) . '" value="1"' . ($checked ? ' checked' : '') . ' ' . $extra . '>'
        . '<label for="' . e($id) . '" class="text-sm text-slate-800">' . e($label) . '</label></div>';
}

/** Small inline icons (outline style, drawn here so no icon font or CDN is needed). */
function icon(string $name, string $class = 'size-5'): string
{
    $paths = [
        'check' => 'M4.5 12.75l6 6 9-13.5',
        'calendar' => 'M6.75 3v2.25M17.25 3v2.25M3 8.25h18M4.5 5.25h15a1.5 1.5 0 011.5 1.5v12a1.5 1.5 0 01-1.5 1.5h-15A1.5 1.5 0 013 18.75v-12a1.5 1.5 0 011.5-1.5z',
        'user' => 'M15.75 6.75a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.25a7.5 7.5 0 0115 0',
        'bed' => 'M3 18.75V6M3 13.5h18v5.25M21 13.5v-1.5a3 3 0 00-3-3h-7.5v4.5M6.75 11.25a1.5 1.5 0 100-3 1.5 1.5 0 000 3z',
        'clock' => 'M12 6v6l3.75 2.25M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'shield' => 'M12 3l7.5 3v5.25c0 4.5-3 8.25-7.5 9.75-4.5-1.5-7.5-5.25-7.5-9.75V6L12 3zM9 12l2 2 4-4',
        'print' => 'M6.75 9V3.75h10.5V9M6.75 18H5.25a1.5 1.5 0 01-1.5-1.5v-6A1.5 1.5 0 015.25 9h13.5a1.5 1.5 0 011.5 1.5v6a1.5 1.5 0 01-1.5 1.5h-1.5M6.75 14.25h10.5V21H6.75v-6.75z',
        'download' => 'M12 3v12m0 0l-4.5-4.5M12 15l4.5-4.5M4.5 20.25h15',
        'menu' => 'M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5',
        'x' => 'M6 6l12 12M18 6L6 18',
        'alert' => 'M12 9v3.75m0 3.75h.008M10.29 3.86L1.82 18a1.5 1.5 0 001.29 2.25h16.78A1.5 1.5 0 0021.18 18L12.71 3.86a1.5 1.5 0 00-2.42 0z',
        'wifi' => 'M8.4 15.4a5 5 0 017.2 0M5.3 12.3a9.4 9.4 0 0113.4 0M2.2 9.2a13.8 13.8 0 0119.6 0M12 19.2h.01',
    ];
    $p = $paths[$name] ?? $paths['check'];
    return '<svg class="' . e($class) . '" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" d="' . e($p) . '"/></svg>';
}

/** Today's date in Manila as Y-m-d. */
function today(): string
{
    return Lib\Dates::manilaToday();
}

function plus_days(string $ymd, int $n): string
{
    return (new DateTimeImmutable($ymd))->modify(($n >= 0 ? '+' : '') . $n . ' day')->format('Y-m-d');
}
