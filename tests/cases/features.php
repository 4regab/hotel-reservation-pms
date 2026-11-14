<?php
declare(strict_types=1);

// Logic checks for the features added after the proof of concept. No database, no network.
use Billing\FinanceService;
use Booking\CancellationPolicy;
use Booking\GuestController;
use Lib\Csv;
use Lib\Dates;
use Lib\Rbac;
use Portal\Accounts;
use Portal\Web\Charts;
use Portal\Web\PublicSite;
use Portal\Web\StaffSite;
use RoomOps\RoomController;

return [
    'csv: quotes, CRLF and a BOM are read; spreadsheet formulas are neutralised on output' => function (): void {
        $rows = Csv::parse("\xEF\xBB\xBFid,name\r\nA-1,\"Lim, Ana\"\r\n\r\nA-2,\"She said \"\"hi\"\"\"\r\n");
        same([['id', 'name'], ['A-1', 'Lim, Ana'], ['A-2', 'She said "hi"']], $rows, 'parsed rows');
        same("'=SUM(A1)", Csv::safeCell('=SUM(A1)'), 'formula is defused');
        same('-12.50', Csv::safeCell('-12.50'), 'negative amounts stay numbers');
        same("id,note\nA-1,\"a,b\"\n", Csv::write([['id', 'note'], ['A-1', 'a,b']]), 'written with quotes');
    },
    'no-show: only possible from 18:00 Manila on the arrival date' => function (): void {
        $arrival = Dates::arrivalAt('2026-03-10');   // 14:00 Manila
        check(!CancellationPolicy::noShowDue('2026-03-10', $arrival + 3 * 3600), 'too early at 17:00');
        check(CancellationPolicy::noShowDue('2026-03-10', $arrival + 4 * 3600), 'due at 18:00');
        check(CancellationPolicy::noShowDue('2026-03-10', $arrival + 5 * 86400), 'still due days later');
    },
    'duplicate guests: same email, same phone digits or same name words group together' => function (): void {
        $g = fn (string $id, string $name, string $email, string $phone) => ['guest_id' => $id, 'full_name' => $name, 'email' => $email, 'phone' => $phone, 'has_account' => 0, 'bookings' => 1, 'created_at' => '2026-01-01'];
        $groups = GuestController::groups([
            $g('a', 'Ana Reyes', 'ana@example.com', '0917 111 2222'), $g('b', 'Reyes Ana', 'other@example.com', '+63 999 000 0000'),
            $g('c', 'Carlo Dizon', 'carlo@example.com', '+63 917 333 4444'), $g('d', 'C. D.', 'x@example.com', '0917 333 4444'),
            $g('e', 'Solo Person', 'solo@example.com', '+63 920 555 6666'),
        ]);
        same(2, count($groups), 'two groups, the loner is left out');
        $ids = array_map(fn ($grp) => array_column($grp['guests'], 'guestId'), $groups);
        sort($ids[0]);
        sort($ids[1]);
        same(true, in_array(['a', 'b'], $ids, true) && in_array(['c', 'd'], $ids, true), 'right members');
    },
    'finance: next business day deadline skips weekends' => function (): void {
        $fri = FinanceService::nextBusinessDayEnd('2026-11-13');   // Friday
        same('2026-11-16', (new DateTimeImmutable('@' . $fri))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d'), 'Friday payments are due Monday');
        $tue = FinanceService::nextBusinessDayEnd('2026-11-10');
        same('2026-11-11', (new DateTimeImmutable('@' . $tue))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d'), 'Tuesday payments are due Wednesday');
    },
    'roles: separation of duties holds in the permission table' => function (): void {
        check(!Rbac::can('cashier', 'export.approve') && Rbac::can('manager', 'export.approve'), 'only a manager approves exports');
        check(!Rbac::can('steward', 'reservation.read') && !Rbac::can('steward', 'payment.read') && Rbac::can('steward', 'guest.merge'), 'steward merges guests and sees no bookings or payments');
        foreach (['housekeeping', 'maintenance'] as $r) {
            foreach (['payment.read', 'refund.request', 'folio.read', 'stay.checkin', 'guest.read', 'reservation.read'] as $a) {
                check(!Rbac::can($r, $a), "{$r} must not do {$a}");
            }
        }
        check(!Rbac::can('it', 'reservation.read') && !Rbac::can('it', 'payment.read') && Rbac::can('it', 'user.admin'), 'IT administers users but sees no bookings or payments');
        check(Rbac::can('reception', 'override.request') && !Rbac::can('reception', 'override.decide') && Rbac::can('manager', 'override.decide'), 'early arrival: reception asks, manager decides');
        check(Rbac::can('guest', 'reservation.list') && !Rbac::can('guest', 'guest.read'), 'guests list their own bookings and cannot search guests');
    },
    'median of cleaning and queue times' => function (): void {
        same(null, RoomController::median([]), 'empty');
        same(5.0, RoomController::median([9, 1, 5]), 'odd count');
        same(6.0, RoomController::median([10, 2, 4, 8]), 'even count');
    },
    'passwords: length and email-name rules' => function (): void {
        Accounts::checkPassword('a-long-enough-pass', 'ana.reyes@example.com');
        throws(fn () => Accounts::checkPassword('short', ''), Lib\ApiException::class, 422);
        throws(fn () => Accounts::checkPassword('ana.reyes-is-my-password', 'ana.reyes@example.com'), Lib\ApiException::class, 422);
    },
    'redirects: only same-site paths are followed after sign-in' => function (): void {
        same('/account', PublicSite::safeNext('/account'), 'path ok');
        same(null, PublicSite::safeNext('//evil.example/x'), 'protocol-relative refused');
        same(null, PublicSite::safeNext('https://evil.example'), 'absolute url refused');
        same(null, PublicSite::safeNext("/ok\nSet-Cookie: x"), 'header injection refused');
    },
    'money text becomes centavos' => function (): void {
        same(125050, StaffSite::centavos('1,250.50'), 'with comma');
        same(85000, StaffSite::centavos('850'), 'whole pesos');
        throws(fn () => StaffSite::centavos('-5'), Lib\ApiException::class, 422);
        throws(fn () => StaffSite::centavos('abc'), Lib\ApiException::class, 422);
    },
    'charts: bars render with a title, a description and one rect per bar' => function (): void {
        require_once dirname(__DIR__, 2) . '/portal/src/Web/helpers.php';
        $svg = Charts::bars('Test chart', 'Three bars', [['label' => '2026-01-01', 'value' => 0.5], ['label' => '2026-01-02', 'value' => 1.0, 'muted' => true], ['label' => '2026-01-03', 'value' => 0.0]], fn ($v) => number_format($v * 100) . '%', 1.0);
        check(str_contains($svg, '<title') && str_contains($svg, '<desc') && substr_count($svg, '<rect') === 3, 'accessible svg with 3 bars');
    },
];
