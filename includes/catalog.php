<?php
/**
 * Read-only queries and small view helpers for the public travel pages.
 */
declare(strict_types=1);

if (!defined('RJ_BOOT')) { http_response_code(403); exit; }

const RJ_RISK_LABELS = [
    'crime_petty'   => 'Petty crime and scams',
    'crime_violent' => 'Violent crime',
    'natural'       => 'Natural hazards',
    'health'        => 'Health',
    'political'     => 'Unrest and terrorism',
    'traffic'       => 'Road safety',
];

const RJ_LEVEL_WORDS = [1 => 'Very low', 2 => 'Low', 3 => 'Medium', 4 => 'High', 5 => 'Very high'];
const RJ_COST_WORDS  = [1 => 'Very cheap', 2 => 'Cheap', 3 => 'Moderate', 4 => 'Expensive', 5 => 'Very expensive'];
const RJ_PRICE_WORDS = [0 => 'Free', 1 => 'Cheap', 2 => 'Moderate', 3 => 'Pricey', 4 => 'Luxury'];
const RJ_DIFFICULTY_WORDS = [1 => 'Easy', 2 => 'Moderate', 3 => 'Challenging', 4 => 'Hard', 5 => 'Extreme'];

function find_country(string $iso2): ?array
{
    $stmt = db()->prepare('SELECT * FROM rj_countries WHERE iso2 = ? AND is_destination = 1');
    $stmt->execute([strtoupper($iso2)]);
    return $stmt->fetch() ?: null;
}

function find_city(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT ci.*, co.iso2, co.name_en AS country_name, co.currency_code, co.cost_index AS country_cost_index
         FROM rj_cities ci JOIN rj_countries co ON co.id = ci.country_id
         WHERE ci.id = ? AND co.is_destination = 1'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function find_place(int $id): ?array
{
    $stmt = db()->prepare(
        "SELECT p.*, cat.slug AS category_slug, cat.name_en AS category_name,
                ci.name_en AS city_name, ci.lat AS city_lat, ci.lng AS city_lng,
                co.id AS country_id, co.iso2, co.name_en AS country_name, co.currency_code
         FROM rj_places p
         JOIN rj_categories cat ON cat.id = p.category_id
         JOIN rj_cities ci ON ci.id = p.city_id
         JOIN rj_countries co ON co.id = ci.country_id
         WHERE p.id = ? AND p.status = 'published' AND co.is_destination = 1"
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function place_tags(int $placeId): array
{
    $stmt = db()->prepare(
        'SELECT t.slug, t.name_en FROM rj_place_tags pt JOIN rj_tags t ON t.id = pt.tag_id WHERE pt.place_id = ? ORDER BY t.name_en'
    );
    $stmt->execute([$placeId]);
    return $stmt->fetchAll();
}

/** Country-wide risk rows keyed by risk_type, plus the weighted overall score. */
function country_risks(int $countryId): array
{
    $stmt = db()->prepare(
        'SELECT risk_type, score, description, how_to_handle, source_url
         FROM rj_risk_scores WHERE country_id = ? AND city_id IS NULL'
    );
    $stmt->execute([$countryId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $rows[$r['risk_type']] = $r;
    }
    // Keep a stable display order
    $ordered = array_filter(array_map(fn ($k) => $rows[$k] ?? null, array_keys(RJ_RISK_LABELS)));

    $stmt = db()->prepare('SELECT overall_risk FROM rj_v_country_risk WHERE country_id = ?');
    $stmt->execute([$countryId]);
    $overall = $stmt->fetchColumn();

    return ['rows' => $ordered, 'overall' => $overall !== false ? (float) $overall : null];
}

/**
 * Visa rule for the logged-in user's passport.
 * @return array{state: string, rule?: array}  state: guest | no_passport | home | found | unknown
 */
function visa_for_current_user(array $country): array
{
    $user = current_user();
    if ($user === null) {
        return ['state' => 'guest'];
    }
    if (empty($user['nationality_country_id'])) {
        return ['state' => 'no_passport'];
    }
    if ((int) $user['nationality_country_id'] === (int) $country['id']) {
        return ['state' => 'home'];
    }
    $stmt = db()->prepare(
        'SELECT v.*, n.name_en AS passport_name FROM rj_visa_rules v
         JOIN rj_countries n ON n.id = v.nationality_country_id
         WHERE v.nationality_country_id = ? AND v.destination_country_id = ?'
    );
    $stmt->execute([$user['nationality_country_id'], $country['id']]);
    $rule = $stmt->fetch();
    if ($rule) {
        return ['state' => 'found', 'rule' => $rule];
    }
    $stmt = db()->prepare('SELECT name_en FROM rj_countries WHERE id = ?');
    $stmt->execute([$user['nationality_country_id']]);
    return ['state' => 'unknown', 'passport' => (string) $stmt->fetchColumn()];
}

/** Render a Leaflet map from a list of points. */
function map_block(string $id, array $points, string $label, int $zoom = 14): string
{
    $json = json_encode(array_values($points), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    return sprintf(
        '<div class="map" data-map="%1$s" data-zoom="%2$d" role="region" aria-label="%3$s"></div>'
        . '<script type="application/json" id="%1$s">%4$s</script>',
        e($id), $zoom, e($label), $json
    );
}

/** Small "level 1–5" meter used for risk and cost. */
function level_meter(int $level, string $word): string
{
    $level = max(1, min(5, $level));
    $cells = '';
    for ($i = 1; $i <= 5; $i++) {
        $cells .= '<span' . ($i <= $level ? ' class="on"' : '') . '></span>';
    }
    return sprintf('<span class="meter meter--%d" role="img" aria-label="%s, %d out of 5">%s</span>', $level, e($word), $level, $cells);
}

function place_url(int $id): string { return url('place.php?id=' . $id); }
function city_url(int $id): string { return url('city.php?id=' . $id); }
function country_url(string $iso2): string { return url('country.php?c=' . strtolower($iso2)); }

/** Paragraphs from plain text with line breaks. */
function paragraphs(?string $text): string
{
    if ($text === null || trim($text) === '') {
        return '';
    }
    $parts = preg_split('/\R{2,}/', trim($text));
    return implode('', array_map(fn ($p) => '<p>' . nl2br(e(trim($p))) . '</p>', $parts));
}
