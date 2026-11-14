<?php
declare(strict_types=1);

namespace Booking;

use Lib\ApiException;
use Lib\Audit;
use Lib\Rbac;
use Lib\Request;
use Lib\ServiceAuth;
use Lib\Tx;
use Lib\Uuid;

/** Guest records: search, create for walk-ins, duplicate review and merge, and the privacy operations. */
final class GuestController
{
    private const ACTIVE = ['HELD', 'CONFIRMED', 'CHECKED_IN'];

    public function __construct(private \PDO $pdo)
    {
    }

    /** GET /internal/guests?q= (reception, manager, steward). Stewards see counts only, never booking details. */
    public function list(Request $r): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'guest.read');
        $args = [];
        $where = 'g.merged_into IS NULL AND g.anonymized_at IS NULL';
        if (!empty($r->query['q'])) {
            $like = '%' . str_replace(['%', '_', '['], ['\\%', '\\_', '\\['], trim((string) $r->query['q'])) . '%';
            $where .= " AND (g.full_name LIKE ? ESCAPE '\\' OR g.email LIKE ? ESCAPE '\\' OR g.phone LIKE ? ESCAPE '\\')";
            array_push($args, $like, $like, $like);
        }
        $st = $this->pdo->prepare(
            "SELECT TOP (100) g.guest_id, g.full_name, g.email, g.phone, CASE WHEN g.account_id IS NOT NULL THEN 1 ELSE 0 END AS has_account, g.created_at,
                    (SELECT COUNT(*) FROM reservation r WHERE r.guest_id = g.guest_id) AS bookings,
                    (SELECT MAX(r.check_out) FROM reservation r WHERE r.guest_id = g.guest_id AND r.status IN ('CHECKED_OUT','CHECKED_IN')) AS last_stay
               FROM guest g WHERE {$where} ORDER BY g.full_name"
        );
        $st->execute($args);
        return [200, ['guests' => array_map(fn ($g) => $this->guestView($g), $st->fetchAll())]];
    }

    /** POST /internal/guests (reception): a guest without an account. Reuses a guest with the same email. */
    public function create(Request $r): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'guest.create');
        $b = $r->json();
        $name = trim((string) ($b['fullName'] ?? ''));
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        $phone = trim((string) ($b['phone'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160 || $phone === '' || strlen($phone) > 40) {
            throw new ApiException(422, 'INVALID_REQUEST', 'fullName, a valid email and a phone number are required');
        }
        $st = $this->pdo->prepare('SELECT TOP (1) guest_id FROM guest WHERE email = ? AND merged_into IS NULL AND anonymized_at IS NULL ORDER BY created_at');
        $st->execute([$email]);
        $existing = $st->fetchColumn();
        if ($existing) {
            return [200, ['guestId' => (string) $existing, 'existing' => true]];
        }
        $id = Uuid::v4();
        $this->pdo->prepare('INSERT INTO guest (guest_id, account_id, full_name, email, phone, created_at) VALUES (?, NULL, ?, ?, ?, DATETRUNC(SECOND, SYSUTCDATETIME()))')->execute([$id, $name, $email, $phone]);
        Audit::record($this->pdo, $user, 'guest.create', 'guest', $id, [], $r->ip());
        return [201, ['guestId' => $id, 'existing' => false]];
    }

    /**
     * GET /internal/guests/duplicates (steward). Groups guests who share an email, the last 9 digits of a phone number,
     * or the same name words in any order. shortcut: loads every active guest into memory; fine for one hotel, page it above ~50k guests.
     */
    public function duplicates(Request $r): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'guest.merge');
        $guests = $this->pdo->query(
            "SELECT g.guest_id, g.full_name, g.email, g.phone, CASE WHEN g.account_id IS NOT NULL THEN 1 ELSE 0 END AS has_account, g.created_at,
                    (SELECT COUNT(*) FROM reservation r WHERE r.guest_id = g.guest_id) AS bookings
               FROM guest g WHERE g.merged_into IS NULL AND g.anonymized_at IS NULL"
        )->fetchAll();
        return [200, ['groups' => self::groups($guests), 'activeGuests' => count($guests)]];
    }

    /** @param list<array> $guests @return list<array{reasons:list<string>,guests:list<array>}> */
    public static function groups(array $guests): array
    {
        $parent = [];
        $find = function (string $x) use (&$parent, &$find): string {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }
            return $x;
        };
        $keys = [];
        foreach ($guests as $g) {
            $parent[$g['guest_id']] = $g['guest_id'];
            $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($g['full_name']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            sort($tokens);
            $digits = preg_replace('/\D+/', '', $g['phone']) ?? '';
            foreach (['email' => strtolower($g['email']), 'phone' => strlen($digits) >= 9 ? substr($digits, -9) : '', 'name' => count($tokens) >= 2 ? implode(' ', $tokens) : ''] as $kind => $val) {
                if ($val !== '') {
                    $keys[$kind . ':' . $val][] = $g['guest_id'];
                }
            }
        }
        $reasons = [];
        foreach ($keys as $k => $ids) {
            if (count($ids) < 2) {
                continue;
            }
            foreach ($ids as $id) {
                $parent[$find($id)] = $find($ids[0]);
            }
            $reasons[$find($ids[0])][] = explode(':', $k, 2)[0];
        }
        $byId = array_column($guests, null, 'guest_id');
        $buckets = [];
        foreach (array_keys($parent) as $id) {
            $buckets[$find($id)][] = $id;
        }
        $out = [];
        foreach ($buckets as $root => $ids) {
            if (count($ids) < 2) {
                continue;
            }
            $kinds = [];
            foreach ($keys as $k => $kids) {
                if (count($kids) > 1 && array_intersect($kids, $ids) === $kids) {
                    $kinds[] = explode(':', $k, 2)[0];
                }
            }
            $out[] = ['reasons' => array_values(array_unique($kinds)), 'guests' => array_map(fn ($id) => self::stewardView($byId[$id]), $ids)];
        }
        return $out;
    }

    private static function stewardView(array $g): array
    {
        return ['guestId' => $g['guest_id'], 'fullName' => $g['full_name'], 'email' => $g['email'], 'phone' => $g['phone'], 'hasAccount' => (bool) $g['has_account'], 'bookings' => (int) $g['bookings'], 'createdAt' => $g['created_at']];
    }

    /** POST /internal/guests/merge (steward): move every booking to the kept guest and retire the other record. */
    public function merge(Request $r): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'guest.merge');
        $b = $r->json();
        $keep = (string) ($b['keepGuestId'] ?? '');
        $drop = (string) ($b['mergeGuestId'] ?? '');
        if (!Uuid::isValid($keep) || !Uuid::isValid($drop) || $keep === $drop) {
            throw new ApiException(422, 'INVALID_REQUEST', 'keepGuestId and mergeGuestId must be two different guest ids');
        }
        $note = isset($b['note']) ? mb_substr(trim((string) $b['note']), 0, 200) : null;
        $res = Tx::run($this->pdo, function () use ($keep, $drop, $user, $note): array {
            $st = $this->pdo->prepare('SELECT * FROM guest WITH (UPDLOCK, ROWLOCK) WHERE guest_id IN (?, ?) ORDER BY guest_id');
            $st->execute([$keep, $drop]);
            $rows = array_column($st->fetchAll(), null, 'guest_id');
            if (count($rows) !== 2) {
                throw new ApiException(404, 'NOT_FOUND', 'Guest not found');
            }
            foreach ($rows as $g) {
                if ($g['merged_into'] !== null || $g['anonymized_at'] !== null) {
                    throw new ApiException(409, 'ALREADY_MERGED', 'One of these guests was already merged or deleted');
                }
            }
            if ($rows[$keep]['account_id'] !== null && $rows[$drop]['account_id'] !== null) {
                throw new ApiException(409, 'BOTH_HAVE_ACCOUNTS', 'Both guests have their own sign-in. Ask them to keep one account first.');
            }
            $moved = $this->pdo->prepare('UPDATE reservation SET guest_id = ? WHERE guest_id = ?');
            $moved->execute([$keep, $drop]);
            $count = $moved->rowCount();
            $accountId = $rows[$drop]['account_id'];
            $this->pdo->prepare('UPDATE guest SET merged_into = ?, account_id = NULL WHERE guest_id = ?')->execute([$keep, $drop]);
            if ($accountId !== null) {
                $this->pdo->prepare('UPDATE guest SET account_id = ? WHERE guest_id = ?')->execute([$accountId, $keep]);
            }
            $this->pdo->prepare('INSERT INTO guest_merge (merge_id, kept_guest_id, merged_guest_id, reservations_moved, merged_by, note, created_at) VALUES (?, ?, ?, ?, ?, ?, DATETRUNC(SECOND, SYSUTCDATETIME()))')
                ->execute([Uuid::v4(), $keep, $drop, $count, $user['accountId'], $note]);
            Audit::record($this->pdo, $user, 'guest.merge', 'guest', $keep, ['mergedGuestId' => $drop, 'reservationsMoved' => $count]);
            return ['keptGuestId' => $keep, 'mergedGuestId' => $drop, 'reservationsMoved' => $count];
        });
        return [200, $res];
    }

    /** GET /internal/privacy/guest-data?accountId= : everything held about one person, for an access request. */
    public function privacyExport(Request $r): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'privacy.subject');
        $acc = $this->accountParam($r->query['accountId'] ?? null);
        $g = $this->pdo->prepare('SELECT guest_id, full_name, email, phone, created_at FROM guest WHERE account_id = ?');
        $g->execute([$acc]);
        $guest = $g->fetch();
        if ($guest === false) {
            return [200, ['guest' => null, 'reservations' => []]];
        }
        $res = $this->pdo->prepare('SELECT r.code, r.status, r.check_in, r.check_out, r.quantity, r.adults, r.quote_minor, r.currency, r.special_requests, r.created_at, t.name AS room_type
                                      FROM reservation r JOIN room_type t ON t.room_type_id = r.room_type_id WHERE r.guest_id = ? ORDER BY r.created_at');
        $res->execute([$guest['guest_id']]);
        $reservations = array_map(function ($row) {
            $row['quote_minor'] = (int) $row['quote_minor'];   // BIGINT comes back as a string
            return $row;
        }, $res->fetchAll());
        return [200, ['guest' => ['fullName' => $guest['full_name'], 'email' => $guest['email'], 'phone' => $guest['phone'], 'createdAt' => $guest['created_at']], 'reservations' => $reservations]];
    }

    /** POST /internal/privacy/correct */
    public function privacyCorrect(Request $r): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'privacy.subject');
        $b = $r->json();
        $acc = $this->accountParam($b['accountId'] ?? null);
        $name = trim((string) ($b['fullName'] ?? ''));
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        $phone = trim((string) ($b['phone'] ?? ''));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '') {
            throw new ApiException(422, 'INVALID_REQUEST', 'fullName, a valid email and phone are required');
        }
        // The BIN2 comparisons count only rows whose text really changes (MySQL reported changed rows, SQL Server reports matched rows).
        $st = $this->pdo->prepare(
            'UPDATE guest SET full_name = ?, email = ?, phone = ? WHERE account_id = ? AND anonymized_at IS NULL
                AND (full_name COLLATE Latin1_General_100_BIN2 <> ? OR email COLLATE Latin1_General_100_BIN2 <> ? OR phone COLLATE Latin1_General_100_BIN2 <> ?)'
        );
        $st->execute([$name, $email, $phone, $acc, $name, $email, $phone]);
        Audit::record($this->pdo, $user, 'privacy.correct_guest', 'account', $acc);
        return [200, ['updated' => $st->rowCount()]];
    }

    /**
     * POST /internal/privacy/anonymize: remove identity from the guest record. Stay and payment facts are kept (finance rules)
     * but no longer point at a person. Refused while a booking is still live.
     */
    public function privacyAnonymize(Request $r): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'privacy.subject');
        $acc = $this->accountParam($r->json()['accountId'] ?? null);
        return Tx::run($this->pdo, function () use ($acc, $user): array {
            $g = $this->pdo->prepare('SELECT guest_id FROM guest WITH (UPDLOCK, ROWLOCK) WHERE account_id = ?');
            $g->execute([$acc]);
            $gid = $g->fetchColumn();
            if ($gid === false) {
                return [200, ['anonymized' => 0]];
            }
            $live = $this->pdo->prepare('SELECT COUNT(*) FROM reservation WHERE guest_id = ? AND status IN (' . implode(',', array_fill(0, count(self::ACTIVE), '?')) . ')');
            $live->execute([$gid, ...self::ACTIVE]);
            if ((int) $live->fetchColumn() > 0) {
                throw new ApiException(409, 'ACTIVE_BOOKINGS', 'This guest still has a held, confirmed or in-house booking. Cancel or complete it first.');
            }
            $this->pdo->prepare("UPDATE guest SET full_name = 'Deleted guest', email = ?, phone = '', account_id = NULL, anonymized_at = DATETRUNC(SECOND, SYSUTCDATETIME()) WHERE guest_id = ?")
                ->execute(['deleted-' . substr((string) $gid, 0, 8) . '@deleted.invalid', $gid]);
            $this->pdo->prepare('UPDATE reservation SET special_requests = NULL WHERE guest_id = ?')->execute([$gid]);
            Audit::record($this->pdo, $user, 'privacy.anonymize_guest', 'account', $acc);
            return [200, ['anonymized' => 1]];
        });
    }

    private function accountParam(mixed $v): string
    {
        return is_string($v) && Uuid::isValid($v) ? $v : throw new ApiException(422, 'INVALID_REQUEST', 'accountId must be a UUID');
    }

    private function guestView(array $g): array
    {
        return ['guestId' => $g['guest_id'], 'fullName' => $g['full_name'], 'email' => $g['email'], 'phone' => $g['phone'], 'hasAccount' => (bool) $g['has_account'], 'bookings' => (int) $g['bookings'], 'lastStay' => $g['last_stay'], 'createdAt' => $g['created_at']];
    }
}
