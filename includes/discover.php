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

    $country = strtoupper($str('country'));
    $f = [
        'country'     => preg_match('/^[A-Z]{2}$/', $country) ? $country : '',
        'city_id'     => $int('city_id', 1, PHP_INT_MAX),
        'group'       => array_key_exists($str('group'), RJ_GROUPS) ? $str('group') : '',
        'max_price'   => $int('max_price', 0, 4),
        'max_risk'    => $int('max_risk', 1, 5),
        'environment' => in_array($str('environment'), ['urban', 'nature'], true) ? $str('environment') : '',
        'terrain'     => $list('terrain', array_keys(RJ_TERRAINS)),
        'themes'      => $list('themes', array_keys(theme_options())),
        'cost'        => $int('cost', 1, 5),
        'imp'         => [],
    ];
    foreach (array_keys(RJ_CRITERIA) as $c) {
        $f['imp'][$c] = $int("imp_{$c}", 0, 3) ?? ($c === 'visa' ? 0 : 1);
    }

    // A city only counts if it belongs to the chosen country (the country may have just changed)
    if ($f['city_id'] !== null) {
        $stmt = db()->prepare('SELECT co.iso2 FROM rj_cities ci JOIN rj_countries co ON co.id = ci.country_id WHERE ci.id = ?');
        $stmt->execute([$f['city_id']]);
        $iso = $stmt->fetchColumn();
        if ($iso === false || ($f['country'] !== '' && $iso !== $f['country'])) {
            $f['city_id'] = null;
        } elseif ($f['country'] === '') {
            $f['country'] = $iso;
        }
    }
    return $f;
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
 * Published places matching the location/type filters, with everything
 * the scoring needs. Small dataset, so the rest is done in PHP.
 */
function candidate_places(array $f): array
{
    $where = ["p.status = 'published'", 'co.is_destination = 1'];
    $params = [];
    if ($f['country'] !== '') {
        $where[] = 'co.iso2 = ?';
        $params[] = $f['country'];
    }
    if ($f['city_id'] !== null) {
        $where[] = 'ci.id = ?';
        $params[] = $f['city_id'];
    }
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
        'Country and city' => $f['country'] !== '' || $f['city_id'] !== null ? ['country' => '', 'city_id' => null] : null,
        'Looking for'      => $f['group'] !== '' ? ['group' => ''] : null,
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

/** Destination countries for dropdowns. */
function destination_list(): array
{
    return db()->query('SELECT iso2, name_en FROM rj_countries WHERE is_destination = 1 ORDER BY name_en')->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Query string that reproduces the current filters (for links). */
function filters_query(array $f, array $override = []): string
{
    $f = array_merge($f, $override);
    $q = [
        'country' => $f['country'], 'city_id' => $f['city_id'], 'group' => $f['group'],
        'max_price' => $f['max_price'], 'max_risk' => $f['max_risk'], 'environment' => $f['environment'],
        'terrain' => $f['terrain'], 'themes' => $f['themes'], 'cost' => $f['cost'],
    ];
    foreach ($f['imp'] as $c => $v) {
        $q["imp_{$c}"] = $v;
    }
    return http_build_query(array_filter($q, fn ($v) => $v !== null && $v !== '' && $v !== []));
}
