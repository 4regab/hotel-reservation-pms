<?php
declare(strict_types=1);

namespace Booking;

use Lib\ApiException;
use Lib\Audit;
use Lib\Db;
use Lib\Rbac;
use Lib\Request;
use Lib\ServiceAuth;
use Lib\Uuid;

/** Room types: public catalogue and manager-controlled rates and descriptions. */
final class CatalogController
{
    public function __construct(private \PDO $pdo, private BookingRepository $repo)
    {
    }

    public static function typeView(array $t): array
    {
        return [
            'roomTypeId' => $t['room_type_id'], 'name' => $t['name'], 'slug' => $t['slug'], 'tagline' => $t['tagline'], 'description' => $t['description'],
            'maxGuests' => (int) $t['max_guests'], 'bedType' => $t['bed_type'], 'sizeSqm' => (int) $t['size_sqm'], 'image' => $t['image'],
            'amenities' => json_decode((string) ($t['amenities'] ?? '[]'), true) ?: [], 'capacity' => (int) $t['capacity'],
            'nightlyMinor' => (int) $t['nightly_rate_minor'], 'currency' => $t['currency'], 'active' => (bool) $t['active'],
        ];
    }

    public function list(Request $r): array
    {
        Rbac::require(ServiceAuth::authenticate($r)['user']['role'], 'roomtype.read');
        return [200, ['roomTypes' => array_map([self::class, 'typeView'], $this->repo->roomTypes())]];
    }

    /** PATCH /internal/room-types/{id}: manager changes the rate or wording. Existing bookings keep their saved price. */
    public function update(Request $r, array $p): array
    {
        $user = ServiceAuth::authenticate($r)['user'];
        Rbac::require($user['role'], 'roomtype.update');
        $t = Uuid::isValid($p['id']) ? $this->repo->roomType($p['id']) : null;
        $t ?? throw new ApiException(404, 'NOT_FOUND');
        $b = $r->json();
        $set = [];
        $args = ['room_type_id' => $p['id']];
        $before = [];
        $after = [];
        if (array_key_exists('nightlyMinor', $b)) {
            if (!is_int($b['nightlyMinor']) || $b['nightlyMinor'] < 100000 || $b['nightlyMinor'] > 5000000) {
                throw new ApiException(422, 'INVALID_REQUEST', 'nightlyMinor must be between PHP 1,000 and PHP 50,000 (in centavos)');
            }
            $set[] = 'nightly_rate_minor';
            $args['nightly_rate_minor'] = $b['nightlyMinor'];
            $before['nightlyMinor'] = (int) $t['nightly_rate_minor'];
            $after['nightlyMinor'] = $b['nightlyMinor'];
        }
        foreach (['tagline' => ['tagline', 120], 'description' => ['description', 700]] as $k => [$col, $max]) {
            if (array_key_exists($k, $b)) {
                if (!is_string($b[$k]) || trim($b[$k]) === '' || mb_strlen($b[$k]) > $max) {
                    throw new ApiException(422, 'INVALID_REQUEST', "{$k} must be text of at most {$max} characters");
                }
                $set[] = $col;
                $args[$col] = trim($b[$k]);
                $after[$k] = true;
            }
        }
        if (array_key_exists('active', $b)) {
            $set[] = 'active';
            $args['active'] = $b['active'] === true ? 1 : 0;
            $after['active'] = $b['active'] === true;
        }
        if ($set === []) {
            throw new ApiException(422, 'INVALID_REQUEST', 'Nothing to change');
        }
        Db::call($this->pdo, 'usp_catalog_updateRoomType', $args);
        Audit::record($this->pdo, $user, 'roomtype.update', 'room_type', $p['id'], ['before' => $before, 'after' => $after], $r->ip());
        return [200, self::typeView($this->repo->roomType($p['id']))];
    }
}
