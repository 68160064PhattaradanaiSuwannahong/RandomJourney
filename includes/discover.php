<?php
/**
 * Shared logic for the recommendation and random systems.
 *
 * Both start from the same filters. The difference:
 *  - Recommend: filters marked "Must have" exclude places; the rest only
 *    change a weighted match score, and results are ranked with reasons.
 *  - Random: every filter the user sets is a hard boundary, then one place
 *    (or a whole trip) is picked at random from what's left.
 */
declare(strict_types=1);

if (!defined('RJ_BOOT')) { http_response_code(403); exit; }

/** What the user is looking for -> category slugs. */
const RJ_GROUPS = [
    'sights'   => ['label' => 'Sights and nature', 'cats' => ['attraction', 'trail']],
    'food'     => ['label' => 'Food and drink',    'cats' => ['restaurant', 'cafe', 'beverage', 'bar']],
    'shopping' => ['label' => 'Shopping',          'cats' => ['market', 'mall', 'souvenir']],
    'stay'     => ['label' => 'Places to stay',    'cats' => ['hotel']],
];

const RJ_TERRAINS = [
    'mountain' => 'Mountains', 'sea' => 'Sea and beaches', 'island' => 'Islands', 'river' => 'Rivers',
    'forest' => 'Forest', 'desert' => 'Desert', 'city' => 'City streets',
];

const RJ_IMPORTANCE = [0 => 'Doesn\'t matter', 1 => 'Nice to have', 2 => 'Important', 3 => 'Must have'];

/** Criteria the user can weigh in the recommendation form. */
const RJ_CRITERIA = [
    'budget'      => 'Budget',
    'safety'      => 'Safety',
    'themes'      => 'Themes',
    'terrain'     => 'Landscape',
    'environment' => 'City or nature',
    'cost'        => 'Cost of living',
    'visa'        => 'Easy visa',
];

/** Themes offered in the forms (tag slugs). */
function theme_options(): array
{
    static $tags = null;
    $tags ??= db()->query('SELECT slug, name_en FROM rj_tags ORDER BY name_en')->fetchAll(PDO::FETCH_KEY_PAIR);
    return $tags;
}

/** Distance choices for the "near me" filter (km). */
const RJ_RADII = [10 => 'Within 10 km', 50 => 'Within 50 km', 200 => 'Within 200 km', 1000 => 'Within 1,000 km', 5000 => 'Within 5,000 km'];

/** Read and clean filters from a request array ($_GET or $_POST). */
function read_filters(array $in): array
{
    $str = static fn (string $k): string => is_string($in[$k] ?? null) ? trim($in[$k]) : '';
    $int = static function (string $k, int $min, int $max) use ($in): ?int {
        $v = filter_var($in[$k] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        return $v === false ? null : $v;
    };
    $list = static fn (string $k, array $allowed): array =>
        array_values(array_intersect(array_filter((array) ($in[$k] ?? []), 'is_string'), $allowed));
    $float = static function (string $k, float $limit) use ($in): ?float {
        $v = filter_var($in[$k] ?? null, FILTER_VALIDATE_FLOAT);
        // Rounded to 2 decimals (about 1 km): enough to measure distance, too coarse to pinpoint a home
        return $v === false || abs($v) > $limit ? null : round($v, 2);
    };

    // Countries and cities: several of each. The old single-value fields still work.
    $destinations = destination_list();
    $countries = array_map('strtoupper', array_filter(array_merge((array) ($in['countries'] ?? []), [$in['country'] ?? null]), 'is_string'));
    $countries = array_values(array_unique(array_intersect($countries, array_keys($destinations))));
    $cityIds = array_merge((array) ($in['cities'] ?? []), [$in['city_id'] ?? null]);
    $cityIds = array_values(array_unique(array_filter(array_map(
        fn ($v) => filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
        $cityIds
    ))));
    $cityIds = array_values(array_intersect($cityIds, array_keys(destination_cities())));

    $radius = $int('radius', 1, 20000);
    $f = [
        'countries'   => $countries,
        'cities'      => array_map('intval', $cityIds),
        'not_home'    => ($in['not_home'] ?? '') === '1',
        'home'        => null,      // ISO2 of the country to leave out, worked out below
        'home_source' => null,      // 'profile', 'location' or 'chosen'
        'lat'         => $float('lat', 90),
        'lng'         => $float('lng', 180),
        'radius'      => $radius !== null && array_key_exists($radius, RJ_RADII) ? $radius : null,
        'group'       => array_key_exists($str('group'), RJ_GROUPS) ? $str('group') : '',
        'max_price'   => $int('max_price', 0, 4),
        'max_risk'    => $int('max_risk', 1, 5),
        'environment' => in_array($str('environment'), ['urban', 'nature'], true) ? $str('environment') : '',
        'terrain'     => $list('terrain', array_keys(RJ_TERRAINS)),
        'themes'      => $list('themes', array_keys(theme_options())),
        'cost'        => $int('cost', 1, 5),
        'imp'         => [],
    ];
    if ($f['lat'] === null || $f['lng'] === null) {
        $f['lat'] = $f['lng'] = null;
    }
    foreach (array_keys(RJ_CRITERIA) as $c) {
        $f['imp'][$c] = $int("imp_{$c}", 0, 3) ?? ($c === 'visa' ? 0 : 1);
    }

    // The country you live in: from your profile, else where you are now, else what you picked
    $user = current_user();
    $chosen = strtoupper($str('home'));
    if ($user && $user['residence_country_id']) {
        $stmt = db()->prepare('SELECT iso2 FROM rj_countries WHERE id = ?');
        $stmt->execute([$user['residence_country_id']]);
        $f['home'] = $stmt->fetchColumn() ?: null;
        $f['home_source'] = $f['home'] ? 'profile' : null;
    }
    if ($f['home'] === null && $f['lat'] !== null) {
        $f['home'] = destination_country_at($f['lat'], $f['lng']);
        $f['home_source'] = $f['home'] ? 'location' : null;
    }
    if ($f['home'] === null && preg_match('/^[A-Z]{2}$/', $chosen)) {
        $f['home'] = $chosen;
        $f['home_source'] = 'chosen';
    }
    return $f;
}

/** Cities of destination countries: id => [name, iso2]. */
function destination_cities(): array
{
    static $rows = null;
    if ($rows === null) {
        $rows = [];
        $stmt = db()->query(
            'SELECT ci.id, ci.name_en, co.iso2 FROM rj_cities ci JOIN rj_countries co ON co.id = ci.country_id
             WHERE co.is_destination = 1 ORDER BY co.name_en, ci.name_en'
        );
        foreach ($stmt as $r) {
            $rows[(int) $r['id']] = ['name' => $r['name_en'], 'iso2' => $r['iso2']];
        }
    }
    return $rows;
}

/**
 * Is the place inside the chosen countries and cities?
 * Ticking cities narrows only their own country: "Japan + Tokyo, Italy" means Tokyo or anywhere in Italy.
 */
function in_scope(array $p, array $f): bool
{
    if ($f['not_home'] && $f['home'] !== null && $p['iso2'] === $f['home']) {
        return false;
    }
    if (!$f['countries'] && !$f['cities']) {
        return true;
    }
    if (in_array((int) $p['city_id'], $f['cities'], true)) {
        return true;
    }
    if (!in_array($p['iso2'], $f['countries'], true)) {
        return false;
    }
    foreach ($f['cities'] as $id) {
        if ((destination_cities()[$id]['iso2'] ?? null) === $p['iso2']) {
            return false; // a city of this country was ticked, and this isn't it
        }
    }
    return true;
}

/** Plain-language summary of where the search looks, for result headings. */
function scope_label(array $f): string
{
    $names = destination_list();
    $parts = array_map(fn ($iso) => $names[$iso] ?? $iso, $f['countries']);
    foreach ($f['cities'] as $id) {
        $parts[] = destination_cities()[$id]['name'] ?? '';
    }
    $label = $parts ? implode(', ', array_filter($parts)) : 'Anywhere';
    if ($f['not_home'] && $f['home']) {
        $label .= ' except ' . ($names[$f['home']] ?? $f['home']);
    }
    if ($f['radius'] && $f['lat'] !== null) {
        $label .= ', ' . strtolower(RJ_RADII[$f['radius']]) . ' of you';
    }
    return $label;
}

/** Which criteria the user actually set a value for. */
function criterion_is_set(array $f, string $c): bool
{
    return match ($c) {
        'budget'      => $f['max_price'] !== null,
        'safety'      => $f['max_risk'] !== null,
        'themes'      => $f['themes'] !== [],
        'terrain'     => $f['terrain'] !== [],
        'environment' => $f['environment'] !== '',
        'cost'        => $f['cost'] !== null,
        'visa'        => current_user() !== null,
    };
}

/** Visa type per destination country id for the current user's passport. */
function visa_map(): array
{
    $user = current_user();
    if (!$user || !$user['nationality_country_id']) {
        return [];
    }
    $stmt = db()->prepare('SELECT destination_country_id, visa_type FROM rj_visa_rules WHERE nationality_country_id = ?');
    $stmt->execute([$user['nationality_country_id']]);
    $map = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $map[(int) $user['nationality_country_id']] = 'home';
    return $map;
}

function visa_is_easy(?string $type): bool
{
    return in_array($type, ['home', 'visa_free', 'visa_on_arrival', 'e_visa'], true);
}

/**
 * Published places matching the where/what/near-me filters, with everything
 * the scoring needs. Small dataset, so the rest is done in PHP.
 */
function candidate_places(array $f): array
{
    $where = ["p.status = 'published'", 'co.is_destination = 1'];
    $params = [];
    if ($f['group'] !== '') {
        $cats = RJ_GROUPS[$f['group']]['cats'];
        $where[] = 'cat.slug IN (' . implode(',', array_fill(0, count($cats), '?')) . ')';
        array_push($params, ...$cats);
    }

    $sql = "SELECT p.id, p.name_en, p.name_th, p.summary, p.lat, p.lng, p.price_level, p.environment, p.terrain,
                   p.is_historical, p.avg_rating, p.review_count, p.difficulty,
                   cat.slug AS category_slug, cat.name_en AS category_name,
                   ci.id AS city_id, ci.name_en AS city_name, ci.lat AS city_lat, ci.lng AS city_lng,
                   COALESCE(ci.cost_index, co.cost_index) AS cost_index,
                   co.id AS country_id, co.iso2, co.name_en AS country_name, co.currency_code,
                   r.overall_risk,
                   GROUP_CONCAT(t.slug ORDER BY t.slug) AS tag_slugs
            FROM rj_places p
            JOIN rj_categories cat ON cat.id = p.category_id
            JOIN rj_cities ci ON ci.id = p.city_id
            JOIN rj_countries co ON co.id = ci.country_id
            LEFT JOIN rj_v_country_risk r ON r.country_id = co.id
            LEFT JOIN rj_place_tags pt ON pt.place_id = p.id
            LEFT JOIN rj_tags t ON t.id = pt.tag_id
            WHERE " . implode(' AND ', $where) . '
            GROUP BY p.id';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $visa = visa_map();
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        if (!in_scope($r, $f)) {
            continue;
        }
        $r['distance'] = $f['lat'] !== null ? distance_km($f['lat'], $f['lng'], (float) $r['lat'], (float) $r['lng']) : null;
        if ($f['radius'] !== null && $r['distance'] !== null && $r['distance'] > $f['radius']) {
            continue;
        }
        $r['tags'] = $r['tag_slugs'] ? explode(',', $r['tag_slugs']) : [];
        $r['visa_type'] = $visa[(int) $r['country_id']] ?? null;
        $rows[] = $r;
    }
    return $rows;
}

/**
 * Score 0..1 for one criterion and a short reason, or null if the
 * criterion doesn't apply to this place.
 *
 * @return array{0: float, 1: ?string}|null
 */
function criterion_match(string $c, array $p, array $f): ?array
{
    $themes = theme_options();
    switch ($c) {
        case 'budget':
            $diff = (int) $p['price_level'] - $f['max_price'];
            return $diff <= 0
                ? [1.0, 'Within your budget (' . strtolower(RJ_PRICE_WORDS[(int) $p['price_level']]) . ')']
                : [max(0.0, 1 - 0.35 * $diff), null];

        case 'safety':
            if ($p['overall_risk'] === null) {
                return [0.5, null];
            }
            $risk = (float) $p['overall_risk'];
            return $risk <= $f['max_risk']
                ? [1.0, 'Risk ' . number_format($risk, 1) . '/5 fits your comfort level']
                : [max(0.0, 1 - 0.4 * ($risk - $f['max_risk'])), null];

        case 'themes':
            $hit = array_values(array_intersect($f['themes'], $p['tags']));
            $score = count($hit) / count($f['themes']);
            return [$score, $hit ? 'Matches ' . implode(', ', array_map(fn ($s) => $themes[$s], $hit)) : null];

        case 'terrain':
            if (in_array($p['terrain'], $f['terrain'], true)) {
                return [1.0, RJ_TERRAINS[$p['terrain']] ?? null];
            }
            // Islands and beaches are close cousins
            $nearSea = in_array($p['terrain'], ['sea', 'island'], true) && array_intersect(['sea', 'island'], $f['terrain']);
            return [$nearSea ? 0.7 : 0.0, null];

        case 'environment':
            if ($p['environment'] === $f['environment']) {
                return [1.0, $f['environment'] === 'nature' ? 'In nature' : 'In the city'];
            }
            return [$p['environment'] === 'mixed' ? 0.6 : 0.0, null];

        case 'cost':
            // The chosen level is a ceiling: cheaper places always fit
            if ($p['cost_index'] === null) {
                return [0.5, null];
            }
            $over = (int) $p['cost_index'] - $f['cost'];
            return $over <= 0
                ? [1.0, RJ_COST_WORDS[(int) $p['cost_index']] . ' cost of living']
                : [max(0.0, 1 - 0.35 * $over), null];

        case 'visa':
            if ($p['visa_type'] === null) {
                return [0.3, null];
            }
            return visa_is_easy($p['visa_type'])
                ? [1.0, $p['visa_type'] === 'home' ? 'Your home country' : (RJ_VISA_TYPES[$p['visa_type']] ?? 'Easy visa') . ' for your passport']
                : [0.0, null];
    }
    return null;
}

/** True if the place passes every "Must have" criterion. */
function passes_musts(array $p, array $f): bool
{
    foreach ($f['imp'] as $c => $imp) {
        if ($imp === 3 && criterion_is_set($f, $c)) {
            [$score] = criterion_match($c, $p, $f);
            $threshold = $c === 'themes' ? 0.01 : 0.99; // themes: at least one must match
            if ($score < $threshold) {
                return false;
            }
        }
    }
    return true;
}

/**
 * Rank places by weighted match.
 * @return array<int, array> places with 'score' (0..100) and 'reasons'
 */
function recommend(array $f, int $limit = 20): array
{
    $results = [];
    foreach (candidate_places($f) as $p) {
        if (!passes_musts($p, $f)) {
            continue;
        }
        $sum = 0.0;
        $weights = 0.0;
        $reasons = [];
        foreach ($f['imp'] as $c => $imp) {
            if ($imp === 0 || !criterion_is_set($f, $c)) {
                continue;
            }
            [$score, $reason] = criterion_match($c, $p, $f);
            $sum += $imp * $score;
            $weights += $imp;
            if ($reason !== null) {
                $reasons[] = $reason;
            }
        }
        // A small nudge for well-reviewed places (never more than 5 points)
        $bonus = (int) $p['review_count'] > 0 ? ((float) $p['avg_rating'] - 3) * 2.5 : 0;
        $p['score'] = (int) round(max(0, min(100, ($weights > 0 ? $sum / $weights * 100 : 50) + $bonus)));
        $p['reasons'] = $reasons;
        $results[] = $p;
    }
    usort($results, fn ($a, $b) => [$b['score'], (float) $b['avg_rating'], $a['name_en']] <=> [$a['score'], (float) $a['avg_rating'], $b['name_en']]);
    return array_slice($results, 0, $limit);
}

/** Places passing every filter the user set (used by Random). */
function filter_strict(array $f): array
{
    $strict = $f;
    foreach (array_keys(RJ_CRITERIA) as $c) {
        $strict['imp'][$c] = $c === 'visa' ? $f['imp']['visa'] : 3;
    }
    return array_values(array_filter(candidate_places($f), fn ($p) => passes_musts($p, $strict)));
}

/**
 * When strict filtering finds nothing, work out which single filter to drop
 * to get results. Returns [criterion label, number of places] or null.
 */
function blocking_filter(array $f): ?array
{
    $best = null;
    $clear = [
        'budget' => ['max_price' => null], 'safety' => ['max_risk' => null], 'themes' => ['themes' => []],
        'terrain' => ['terrain' => []], 'environment' => ['environment' => ''], 'cost' => ['cost' => null],
    ];
    foreach ($clear as $c => $reset) {
        if (!criterion_is_set($f, $c)) {
            continue;
        }
        $n = count(filter_strict(array_merge($f, $reset)));
        if ($n > 0 && ($best === null || $n > $best[1])) {
            $best = [RJ_CRITERIA[$c], $n];
        }
    }
    // Location and type filters can be the blocker too
    $other = [
        'Countries and cities' => $f['countries'] || $f['cities'] ? ['countries' => [], 'cities' => []] : null,
        'Not my home country'  => $f['not_home'] && $f['home'] ? ['not_home' => false] : null,
        'Distance from you'    => $f['radius'] !== null && $f['lat'] !== null ? ['radius' => null] : null,
        'Looking for'          => $f['group'] !== '' ? ['group' => ''] : null,
    ];
    foreach (array_filter($other) as $label => $reset) {
        $n = count(filter_strict(array_merge($f, $reset)));
        if ($n > 0 && ($best === null || $n > $best[1])) {
            $best = [$label, $n];
        }
    }
    if ($f['imp']['visa'] === 3) {
        $relaxed = $f;
        $relaxed['imp']['visa'] = 0;
        $n = count(filter_strict($relaxed));
        if ($n > 0 && ($best === null || $n > $best[1])) {
            $best = [RJ_CRITERIA['visa'], $n];
        }
    }
    return $best;
}

/** Destination countries for dropdowns: iso2 => English name. */
function destination_list(): array
{
    static $list = null;
    $list ??= db()->query('SELECT iso2, name_en FROM rj_countries WHERE is_destination = 1 ORDER BY name_en')->fetchAll(PDO::FETCH_KEY_PAIR);
    return $list;
}

/** Query string that reproduces the current filters (for links). Location is never put in a URL. */
function filters_query(array $f, array $override = []): string
{
    $f = array_merge($f, $override);
    $q = [
        'countries' => $f['countries'], 'cities' => $f['cities'], 'not_home' => $f['not_home'] ? 1 : null,
        'radius' => $f['radius'], 'group' => $f['group'],
        'max_price' => $f['max_price'], 'max_risk' => $f['max_risk'], 'environment' => $f['environment'],
        'terrain' => $f['terrain'], 'themes' => $f['themes'], 'cost' => $f['cost'],
    ];
    foreach ($f['imp'] as $c => $v) {
        $q["imp_{$c}"] = $v;
    }
    return http_build_query(array_filter($q, fn ($v) => $v !== null && $v !== '' && $v !== []));
}
