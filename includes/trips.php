<?php
/**
 * Trip planner: a user's trips, each a day-by-day list of places with an
 * estimated budget in the user's own currency.
 */
declare(strict_types=1);

if (!defined('RJ_BOOT')) { http_response_code(403); exit; }

const RJ_TRIP_MAX_DAYS = 30;
const RJ_TRIP_MAX_ITEMS = 120;

/** The current user's trips, newest first, with item counts. */
function user_trips(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT t.*, COUNT(i.id) AS items
         FROM rj_trips t LEFT JOIN rj_trip_items i ON i.trip_id = t.id
         WHERE t.user_id = ? GROUP BY t.id ORDER BY t.updated_at DESC, t.id DESC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** One trip, only if it belongs to the user. */
function find_trip(int $tripId, int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM rj_trips WHERE id = ? AND user_id = ?');
    $stmt->execute([$tripId, $userId]);
    return $stmt->fetch() ?: null;
}

/** Places in a trip, in day and visiting order. */
function trip_items(int $tripId): array
{
    $stmt = db()->prepare(
        "SELECT i.id AS item_id, i.day_no, i.sort_order, i.note,
                p.id, p.name_en, p.summary, p.lat, p.lng, p.price_level, p.price_min, p.price_max, p.duration_hours,
                cat.slug AS category_slug, cat.name_en AS category_name,
                ci.name_en AS city_name, co.iso2, co.name_en AS country_name, co.currency_code
         FROM rj_trip_items i
         JOIN rj_places p ON p.id = i.place_id
         JOIN rj_categories cat ON cat.id = p.category_id
         JOIN rj_cities ci ON ci.id = p.city_id
         JOIN rj_countries co ON co.id = ci.country_id
         WHERE i.trip_id = ? AND p.status = 'published'
         ORDER BY i.day_no, i.sort_order, i.id"
    );
    $stmt->execute([$tripId]);
    return $stmt->fetchAll();
}

/**
 * Typical cost of a place in the target currency: [low, high], or null if we have no price.
 * Free places cost 0.
 */
function item_cost(array $p, string $to): ?array
{
    if ((int) $p['price_level'] === 0 && $p['price_min'] === null && $p['price_max'] === null) {
        return [0.0, 0.0];
    }
    if ($p['price_min'] === null && $p['price_max'] === null) {
        return null;
    }
    $lo = (float) ($p['price_min'] ?? $p['price_max']);
    $hi = (float) ($p['price_max'] ?? $p['price_min']);
    $from = (string) $p['currency_code'];
    $a = fx_convert($lo, $from, $to);
    $b = fx_convert($hi, $from, $to);
    return $a === null || $b === null ? null : [$a, $b];
}

/** Sum of costs: [low, high, number of places without a price]. */
function trip_budget(array $items, string $to): array
{
    $lo = $hi = 0.0;
    $unknown = 0;
    foreach ($items as $p) {
        $c = item_cost($p, $to);
        if ($c === null) {
            $unknown++;
            continue;
        }
        $lo += $c[0];
        $hi += $c[1];
    }
    return [$lo, $hi, $unknown];
}

/** Next sort position at the end of a day. */
function trip_next_sort(int $tripId, int $day): int
{
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM rj_trip_items WHERE trip_id = ? AND day_no = ?');
    $stmt->execute([$tripId, $day]);
    return (int) $stmt->fetchColumn();
}

/** Mark a trip as just changed (it moves to the top of the list). */
function touch_trip(int $tripId): void
{
    db()->prepare('UPDATE rj_trips SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$tripId]);
}
