<?php
/**
 * Admin panel engine.
 *
 * Each content type (city, place, risk ...) is described once in admin_entities():
 * its table, list query and form fields. list.php / edit.php / delete.php read
 * that description, so table and column names only ever come from this file,
 * never from the request.
 */
declare(strict_types=1);

if (!defined('RJ_BOOT')) { http_response_code(403); exit; }

const RJ_RISK_TYPES = [
    'crime_petty'   => 'Petty crime (pickpocketing, scams)',
    'crime_violent' => 'Violent crime',
    'natural'       => 'Natural hazards',
    'health'        => 'Health',
    'political'     => 'Political unrest / terrorism',
    'traffic'       => 'Road and traffic',
];

const RJ_VISA_TYPES = [
    'visa_free'       => 'Visa free',
    'visa_on_arrival' => 'Visa on arrival',
    'e_visa'          => 'e-Visa',
    'visa_required'   => 'Visa required',
    'not_allowed'     => 'Entry not allowed',
];

// ---------------------------------------------------------------------
// Option lists for <select> fields
// ---------------------------------------------------------------------

function admin_options(string $source): array
{
    static $cache = [];
    if (isset($cache[$source])) {
        return $cache[$source];
    }

    $sql = match ($source) {
        'destinations' => 'SELECT id, name_en FROM rj_countries WHERE is_destination = 1 ORDER BY name_en',
        'countries'    => 'SELECT id, name_en FROM rj_countries ORDER BY name_en',
        'non_destinations' => 'SELECT id, name_en FROM rj_countries WHERE is_destination = 0 ORDER BY name_en',
        'cities'       => "SELECT ci.id, CONCAT(co.name_en, ' – ', ci.name_en)
                           FROM rj_cities ci JOIN rj_countries co ON co.id = ci.country_id
                           ORDER BY co.name_en, ci.name_en",
        'places'       => "SELECT p.id, CONCAT(ci.name_en, ' – ', p.name_en)
                           FROM rj_places p JOIN rj_cities ci ON ci.id = p.city_id
                           ORDER BY ci.name_en, p.name_en",
        'categories'   => 'SELECT id, name_en FROM rj_categories ORDER BY sort_order',
        'tags'         => 'SELECT id, name_en FROM rj_tags ORDER BY name_en',
        default        => null,
    };

    if ($sql !== null) {
        $rows = db()->query($sql)->fetchAll(PDO::FETCH_NUM);
        $cache[$source] = array_column($rows, 1, 0);
        return $cache[$source];
    }

    $cache[$source] = match ($source) {
        'environment'  => ['urban' => 'Urban', 'nature' => 'Nature', 'mixed' => 'Mixed'],
        'terrain'      => ['city' => 'City', 'mountain' => 'Mountain', 'sea' => 'Sea / beach', 'island' => 'Island',
                           'river' => 'River', 'forest' => 'Forest', 'desert' => 'Desert', 'other' => 'Other'],
        'level_1_5'    => [1 => '1 – very low', 2 => '2 – low', 3 => '3 – medium', 4 => '4 – high', 5 => '5 – very high'],
        'cost_1_5'     => [1 => '1 – very cheap', 2 => '2 – cheap', 3 => '3 – moderate', 4 => '4 – expensive', 5 => '5 – very expensive'],
        'difficulty'   => [1 => '1 – easy', 2 => '2 – moderate', 3 => '3 – challenging', 4 => '4 – hard', 5 => '5 – extreme'],
        'price_level'  => [0 => 'Free', 1 => 'Cheap', 2 => 'Moderate', 3 => 'Pricey', 4 => 'Luxury'],
        'advisory'     => [1 => '1 – Normal precautions', 2 => '2 – Increased caution', 3 => '3 – Reconsider travel', 4 => '4 – Do not travel'],
        'status'       => ['published' => 'Published', 'draft' => 'Draft (hidden)', 'pending' => 'Pending review'],
        'risk_type'    => RJ_RISK_TYPES,
        'risk_type_place' => RJ_RISK_TYPES + ['other' => 'Other'],
        'visa_type'    => RJ_VISA_TYPES,
        default        => throw new LogicException("Unknown option source: {$source}"),
    };
    return $cache[$source];
}

// ---------------------------------------------------------------------
// Entity definitions
// ---------------------------------------------------------------------

function admin_entities(): array
{
    return [
        'country' => [
            'title'    => 'Destinations',
            'singular' => 'destination',
            'table'    => 'rj_countries',
            'can_create' => false,               // countries already exist; you mark them as destinations
            'delete'   => ['type' => 'unflag', 'column' => 'is_destination', 'label' => 'Remove from destinations'],
            'list' => [
                'from'    => 'rj_countries c',
                'select'  => "c.id, c.iso2, c.name_en, c.name_th, c.currency_code, c.cost_index, c.advisory_level,
                              (SELECT COUNT(*) FROM rj_cities ci WHERE ci.country_id = c.id) AS cities,
                              (SELECT COUNT(*) FROM rj_risk_scores r WHERE r.country_id = c.id AND r.city_id IS NULL) AS risks",
                'where'   => 'c.is_destination = 1',
                'order'   => 'c.name_en',
                'search'  => ['c.name_en', 'c.name_th'],
                'columns' => ['iso2' => 'Code', 'name_en' => 'Country', 'name_th' => 'Thai name', 'currency_code' => 'Currency',
                              'cost_index' => 'Cost', 'advisory_level' => 'Advisory', 'cities' => 'Cities', 'risks' => 'Risk rows'],
            ],
            'fields' => [
                ['name' => 'name_th', 'label' => 'Thai name', 'type' => 'text', 'max' => 100],
                ['name' => 'capital', 'label' => 'Capital', 'type' => 'text', 'max' => 100, 'half' => true],
                ['name' => 'languages', 'label' => 'Languages', 'type' => 'text', 'max' => 150, 'half' => true],
                ['name' => 'timezone', 'label' => 'Time zone', 'type' => 'text', 'max' => 50, 'half' => true, 'hint' => 'e.g. UTC+9'],
                ['name' => 'plug_types', 'label' => 'Plug types', 'type' => 'text', 'max' => 30, 'half' => true, 'hint' => 'e.g. A, B'],
                ['name' => 'best_season', 'label' => 'Best time to visit', 'type' => 'text', 'max' => 150],
                ['name' => 'summary', 'label' => 'Summary', 'type' => 'textarea', 'rows' => 3],
                ['name' => 'history', 'label' => 'History', 'type' => 'textarea'],
                ['name' => 'culture', 'label' => 'Culture', 'type' => 'textarea'],
                ['name' => 'beliefs', 'label' => 'Beliefs and religion', 'type' => 'textarea'],
                ['name' => 'etiquette', 'label' => 'Etiquette (do and don\'t)', 'type' => 'textarea'],
                ['section' => 'Cost of living', 'hint' => 'Typical prices in the local currency.'],
                ['name' => 'cost_index', 'label' => 'Cost level', 'type' => 'select', 'options' => 'cost_1_5', 'nullable' => true, 'half' => true],
                ['name' => 'price_meal', 'label' => 'Local meal', 'type' => 'decimal', 'min' => 0, 'half' => true],
                ['name' => 'price_coffee', 'label' => 'Cup of coffee', 'type' => 'decimal', 'min' => 0, 'half' => true],
                ['name' => 'price_hotel_night', 'label' => 'Mid-range hotel, 1 night', 'type' => 'decimal', 'min' => 0, 'half' => true],
                ['name' => 'price_local_transport', 'label' => 'Local transport ticket', 'type' => 'decimal', 'min' => 0, 'half' => true],
                ['section' => 'Safety and emergencies'],
                ['name' => 'advisory_level', 'label' => 'Official advisory level', 'type' => 'select', 'options' => 'advisory', 'nullable' => true, 'half' => true],
                ['name' => 'advisory_url', 'label' => 'Advisory source URL', 'type' => 'url', 'half' => true],
                ['name' => 'emergency_police', 'label' => 'Police', 'type' => 'text', 'max' => 20, 'half' => true],
                ['name' => 'emergency_ambulance', 'label' => 'Ambulance', 'type' => 'text', 'max' => 20, 'half' => true],
                ['name' => 'emergency_fire', 'label' => 'Fire', 'type' => 'text', 'max' => 20, 'half' => true],
                ['name' => 'tourist_police', 'label' => 'Tourist police', 'type' => 'text', 'max' => 30, 'half' => true],
                ['name' => 'hero_image_url', 'label' => 'Cover image URL', 'type' => 'url'],
            ],
            'children' => [
                ['entity' => 'city', 'filter' => 'country_id', 'label' => 'Cities'],
                ['entity' => 'risk', 'filter' => 'country_id', 'label' => 'Risk scores'],
                ['entity' => 'visa', 'filter' => 'destination_country_id', 'label' => 'Visa rules'],
            ],
        ],

        'city' => [
            'title'    => 'Cities',
            'singular' => 'city',
            'table'    => 'rj_cities',
            'list' => [
                'from'    => 'rj_cities ci JOIN rj_countries co ON co.id = ci.country_id',
                'select'  => 'ci.id, ci.name_en, ci.name_th, co.name_en AS country, ci.environment,
                              (SELECT COUNT(*) FROM rj_places p WHERE p.city_id = ci.id) AS places',
                'order'   => 'co.name_en, ci.name_en',
                'search'  => ['ci.name_en', 'ci.name_th'],
                'filters' => ['country_id' => ['ci.country_id', 'destinations', 'All countries']],
                'columns' => ['name_en' => 'City', 'name_th' => 'Thai name', 'country' => 'Country',
                              'environment' => 'Type', 'places' => 'Places'],
            ],
            'fields' => [
                ['name' => 'country_id', 'label' => 'Country', 'type' => 'select', 'options' => 'destinations', 'required' => true],
                ['name' => 'name_en', 'label' => 'Name (English)', 'type' => 'text', 'max' => 100, 'required' => true, 'half' => true],
                ['name' => 'name_th', 'label' => 'Name (Thai)', 'type' => 'text', 'max' => 100, 'half' => true],
                ['name' => 'coords', 'label' => 'Coordinates', 'type' => 'latlng', 'required' => true],
                ['name' => 'environment', 'label' => 'City or nature', 'type' => 'select', 'options' => 'environment', 'required' => true, 'half' => true],
                ['name' => 'cost_index', 'label' => 'Cost level', 'type' => 'select', 'options' => 'cost_1_5', 'nullable' => true, 'half' => true,
                 'hint' => 'Leave empty to use the country\'s level.'],
                ['name' => 'best_season', 'label' => 'Best time to visit', 'type' => 'text', 'max' => 150],
                ['name' => 'summary', 'label' => 'Summary', 'type' => 'textarea', 'rows' => 3],
                ['name' => 'history', 'label' => 'History', 'type' => 'textarea'],
                ['name' => 'hero_image_url', 'label' => 'Cover image URL', 'type' => 'url'],
            ],
            'children' => [
                ['entity' => 'place', 'filter' => 'city_id', 'label' => 'Places'],
            ],
        ],

        'place' => [
            'title'    => 'Places',
            'singular' => 'place',
            'table'    => 'rj_places',
            'on_insert' => ['created_by' => 'current_user_id'],
            'list' => [
                'from'    => 'rj_places p JOIN rj_cities ci ON ci.id = p.city_id JOIN rj_categories cat ON cat.id = p.category_id',
                'select'  => 'p.id, p.name_en, ci.name_en AS city, cat.name_en AS category, p.price_level, p.status,
                              p.review_count, (SELECT COUNT(*) FROM rj_place_safety s WHERE s.place_id = p.id) AS tips',
                'order'   => 'ci.name_en, cat.sort_order, p.name_en',
                'search'  => ['p.name_en', 'p.name_th', 'p.summary'],
                'filters' => [
                    'city_id'     => ['p.city_id', 'cities', 'All cities'],
                    'category_id' => ['p.category_id', 'categories', 'All categories'],
                ],
                'columns' => ['name_en' => 'Place', 'city' => 'City', 'category' => 'Category',
                              'price_level' => 'Price', 'status' => 'Status', 'tips' => 'Safety tips', 'review_count' => 'Reviews'],
            ],
            'fields' => [
                ['name' => 'city_id', 'label' => 'City', 'type' => 'select', 'options' => 'cities', 'required' => true, 'half' => true],
                ['name' => 'category_id', 'label' => 'Category', 'type' => 'select', 'options' => 'categories', 'required' => true, 'half' => true],
                ['name' => 'name_en', 'label' => 'Name (English)', 'type' => 'text', 'max' => 150, 'required' => true, 'half' => true],
                ['name' => 'name_th', 'label' => 'Name (Thai)', 'type' => 'text', 'max' => 150, 'half' => true],
                ['name' => 'summary', 'label' => 'One-line summary', 'type' => 'text', 'max' => 300, 'hint' => 'Shown on cards and search results.'],
                ['name' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                ['name' => 'history', 'label' => 'History', 'type' => 'textarea'],
                ['name' => 'culture_beliefs', 'label' => 'Culture and beliefs', 'type' => 'textarea'],
                ['section' => 'Location'],
                ['name' => 'coords', 'label' => 'Coordinates', 'type' => 'latlng', 'required' => true],
                ['name' => 'address', 'label' => 'Address', 'type' => 'text', 'max' => 300],
                ['name' => 'how_to_get_there', 'label' => 'How to get there', 'type' => 'textarea', 'rows' => 3],
                ['section' => 'Filters', 'hint' => 'Used by the recommendation and random systems.'],
                ['name' => 'price_level', 'label' => 'Price level', 'type' => 'select', 'options' => 'price_level', 'required' => true, 'half' => true],
                ['name' => 'environment', 'label' => 'City or nature', 'type' => 'select', 'options' => 'environment', 'required' => true, 'half' => true],
                ['name' => 'terrain', 'label' => 'Terrain', 'type' => 'select', 'options' => 'terrain', 'required' => true, 'half' => true],
                ['name' => 'is_historical', 'label' => 'Historical site', 'type' => 'bool', 'half' => true],
                ['name' => 'price_min', 'label' => 'Price from (local currency)', 'type' => 'decimal', 'min' => 0, 'half' => true],
                ['name' => 'price_max', 'label' => 'Price to (local currency)', 'type' => 'decimal', 'min' => 0, 'half' => true],
                ['name' => 'difficulty', 'label' => 'Trail difficulty', 'type' => 'select', 'options' => 'difficulty', 'nullable' => true, 'half' => true,
                 'hint' => 'Hiking trails only.'],
                ['name' => 'duration_hours', 'label' => 'Suggested time (hours)', 'type' => 'decimal', 'min' => 0, 'max' => 999, 'half' => true],
                ['name' => 'tags', 'label' => 'Tags', 'type' => 'tags'],
                ['section' => 'Practical info'],
                ['name' => 'opening_hours', 'label' => 'Opening hours', 'type' => 'text', 'max' => 200],
                ['name' => 'dress_code', 'label' => 'Dress code', 'type' => 'text', 'max' => 200],
                ['name' => 'permit_required', 'label' => 'Permit or advance booking required', 'type' => 'bool'],
                ['name' => 'permit_info', 'label' => 'Permit details', 'type' => 'textarea', 'rows' => 3],
                ['name' => 'website_url', 'label' => 'Official website', 'type' => 'url', 'half' => true],
                ['name' => 'image_url', 'label' => 'Image URL', 'type' => 'url', 'half' => true],
                ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => 'status', 'required' => true, 'half' => true],
            ],
            'children' => [
                ['entity' => 'safety', 'filter' => 'place_id', 'label' => 'Safety tips'],
            ],
        ],

        'safety' => [
            'title'    => 'Place safety tips',
            'singular' => 'safety tip',
            'table'    => 'rj_place_safety',
            'list' => [
                'from'    => 'rj_place_safety s JOIN rj_places p ON p.id = s.place_id',
                'select'  => 's.id, s.title, p.name_en AS place, s.risk_type',
                'order'   => 'p.name_en, s.id',
                'search'  => ['s.title', 's.tip'],
                'filters' => ['place_id' => ['s.place_id', 'places', 'All places']],
                'columns' => ['title' => 'Tip', 'place' => 'Place', 'risk_type' => 'Risk type'],
            ],
            'fields' => [
                ['name' => 'place_id', 'label' => 'Place', 'type' => 'select', 'options' => 'places', 'required' => true],
                ['name' => 'risk_type', 'label' => 'Risk type', 'type' => 'select', 'options' => 'risk_type_place', 'required' => true],
                ['name' => 'title', 'label' => 'Short title', 'type' => 'text', 'max' => 150, 'required' => true],
                ['name' => 'tip', 'label' => 'What to watch out for and what to do', 'type' => 'textarea', 'required' => true],
            ],
        ],

        'risk' => [
            'title'    => 'Risk scores',
            'singular' => 'risk score',
            'table'    => 'rj_risk_scores',
            'list' => [
                'from'    => 'rj_risk_scores r JOIN rj_countries co ON co.id = r.country_id LEFT JOIN rj_cities ci ON ci.id = r.city_id',
                'select'  => "r.id, co.name_en AS country, COALESCE(ci.name_en, 'Whole country') AS area, r.risk_type, r.score",
                'order'   => 'co.name_en, r.city_id IS NOT NULL, ci.name_en, r.risk_type',
                'search'  => ['r.description', 'r.how_to_handle'],
                'filters' => ['country_id' => ['r.country_id', 'destinations', 'All countries']],
                'columns' => ['country' => 'Country', 'area' => 'Area', 'risk_type' => 'Risk', 'score' => 'Score (1–5)'],
            ],
            'unique'  => [['country_id', 'city_id', 'risk_type'], 'This country/city already has a score for that risk. Edit the existing one instead.'],
            'fields' => [
                ['name' => 'country_id', 'label' => 'Country', 'type' => 'select', 'options' => 'destinations', 'required' => true, 'half' => true],
                ['name' => 'city_id', 'label' => 'City (optional)', 'type' => 'select', 'options' => 'cities', 'nullable' => true, 'half' => true,
                 'hint' => 'Leave empty for a country-wide score.'],
                ['name' => 'risk_type', 'label' => 'Risk', 'type' => 'select', 'options' => 'risk_type', 'required' => true, 'half' => true],
                ['name' => 'score', 'label' => 'Score', 'type' => 'select', 'options' => 'level_1_5', 'required' => true, 'half' => true],
                ['name' => 'description', 'label' => 'What the risk is', 'type' => 'textarea', 'rows' => 3],
                ['name' => 'how_to_handle', 'label' => 'How to prevent it / what to do', 'type' => 'textarea', 'rows' => 4],
                ['name' => 'source_url', 'label' => 'Source URL', 'type' => 'url'],
            ],
        ],

        'visa' => [
            'title'    => 'Visa rules',
            'singular' => 'visa rule',
            'table'    => 'rj_visa_rules',
            'list' => [
                'from'    => 'rj_visa_rules v JOIN rj_countries n ON n.id = v.nationality_country_id
                              JOIN rj_countries d ON d.id = v.destination_country_id',
                'select'  => 'v.id, n.name_en AS passport, d.name_en AS destination, v.visa_type, v.max_stay_days, v.verified_at',
                'order'   => 'd.name_en, n.name_en',
                'search'  => ['n.name_en', 'd.name_en'],
                'filters' => [
                    'destination_country_id' => ['v.destination_country_id', 'destinations', 'All destinations'],
                    'nationality_country_id' => ['v.nationality_country_id', 'countries', 'All passports'],
                ],
                'columns' => ['passport' => 'Passport', 'destination' => 'Destination', 'visa_type' => 'Type',
                              'max_stay_days' => 'Max days', 'verified_at' => 'Checked on'],
            ],
            'unique'  => [['nationality_country_id', 'destination_country_id'], 'A rule for this passport and destination already exists.'],
            'fields' => [
                ['name' => 'nationality_country_id', 'label' => 'Passport', 'type' => 'select', 'options' => 'countries', 'required' => true, 'half' => true],
                ['name' => 'destination_country_id', 'label' => 'Destination', 'type' => 'select', 'options' => 'destinations', 'required' => true, 'half' => true],
                ['name' => 'visa_type', 'label' => 'Visa type', 'type' => 'select', 'options' => 'visa_type', 'required' => true, 'half' => true],
                ['name' => 'max_stay_days', 'label' => 'Max stay (days)', 'type' => 'int', 'min' => 1, 'max' => 3650, 'half' => true],
                ['name' => 'fee_text', 'label' => 'Fee', 'type' => 'text', 'max' => 100, 'hint' => 'e.g. "USD 25" or "Free"'],
                ['name' => 'summary', 'label' => 'Summary', 'type' => 'textarea', 'rows' => 3],
                ['name' => 'how_to_apply', 'label' => 'How to apply', 'type' => 'textarea'],
                ['name' => 'official_url', 'label' => 'Official source URL', 'type' => 'url', 'half' => true],
                ['name' => 'verified_at', 'label' => 'Last checked on', 'type' => 'date', 'half' => true],
            ],
        ],
    ];
}

function admin_entity(string $key): array
{
    $entities = admin_entities();
    if (!isset($entities[$key])) {
        http_response_code(404);
        exit('Unknown content type.');
    }
    return $entities[$key] + ['key' => $key, 'can_create' => true, 'delete' => ['type' => 'hard'], 'children' => [], 'on_insert' => []];
}

/** Real DB columns behind the form fields (latlng -> lat, lng; tags have none). */
function admin_columns(array $entity): array
{
    $cols = [];
    foreach ($entity['fields'] as $f) {
        if (!isset($f['name'])) {
            continue;
        }
        match ($f['type']) {
            'latlng' => array_push($cols, 'lat', 'lng'),
            'tags'   => null,
            default  => $cols[] = $f['name'],
        };
    }
    return $cols;
}

// ---------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------

/**
 * Turn raw POST data into DB values.
 *
 * @return array{values: array<string,mixed>, tags: ?array<int>, errors: array<string,string>, form: array<string,mixed>}
 */
function admin_validate(array $entity, array $post): array
{
    $values = [];
    $errors = [];
    $form = [];
    $tags = null;

    foreach ($entity['fields'] as $f) {
        if (!isset($f['name'])) {
            continue;
        }
        $name = $f['name'];
        $raw = $post[$name] ?? null;
        $required = !empty($f['required']);

        if ($f['type'] === 'tags') {
            $options = admin_options('tags');
            $tags = [];
            foreach ((array) $raw as $id) {
                if (is_string($id) && ctype_digit($id) && isset($options[(int) $id])) {
                    $tags[] = (int) $id;
                }
            }
            $form[$name] = $tags;
            continue;
        }

        if ($f['type'] === 'bool') {
            $values[$name] = !empty($raw) ? 1 : 0;
            $form[$name] = $values[$name];
            continue;
        }

        $str = is_string($raw) ? trim($raw) : '';
        $form[$name] = $str;

        if ($str === '') {
            if ($required) {
                $errors[$name] = 'This field is required.';
            }
            if ($f['type'] === 'latlng') {
                $values['lat'] = $values['lng'] = null;
            } else {
                // Optional text stays NULL rather than ''
                $values[$name] = null;
            }
            continue;
        }

        switch ($f['type']) {
            case 'text':
            case 'textarea':
                $max = $f['max'] ?? 65000;
                if (mb_strlen($str) > $max) {
                    $errors[$name] = "Use at most {$max} characters.";
                }
                $values[$name] = $str;
                break;

            case 'url':
                if (!filter_var($str, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $str) || mb_strlen($str) > 500) {
                    $errors[$name] = 'Enter a full web address starting with https://';
                }
                $values[$name] = $str;
                break;

            case 'int':
            case 'decimal':
                $str = str_replace(',', '', $str);
                $ok = $f['type'] === 'int' ? preg_match('/^-?\d+$/', $str) : is_numeric($str);
                $num = $f['type'] === 'int' ? (int) $str : (float) $str;
                if (!$ok) {
                    $errors[$name] = $f['type'] === 'int' ? 'Enter a whole number.' : 'Enter a number.';
                } elseif (isset($f['min']) && $num < $f['min']) {
                    $errors[$name] = "Must be at least {$f['min']}.";
                } elseif (isset($f['max']) && $num > $f['max']) {
                    $errors[$name] = "Must be at most {$f['max']}.";
                }
                $values[$name] = $num;
                break;

            case 'select':
                $options = admin_options($f['options']);
                if (!array_key_exists($str, $options)) {
                    $errors[$name] = 'Choose an option from the list.';
                }
                $values[$name] = ctype_digit($str) ? (int) $str : $str;
                break;

            case 'date':
                $d = DateTime::createFromFormat('!Y-m-d', $str);
                if (!$d || $d->format('Y-m-d') !== $str) {
                    $errors[$name] = 'Enter a date like 2026-10-28.';
                }
                $values[$name] = $str;
                break;

            case 'latlng':
                // Accepts "13.7563, 100.5018" as copied from Google Maps
                if (!preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*[,\s]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $str, $m)
                    || abs((float) $m[1]) > 90 || abs((float) $m[2]) > 180) {
                    $errors[$name] = 'Paste coordinates like "13.7563, 100.5018" (latitude, longitude).';
                    $values['lat'] = $values['lng'] = null;
                } else {
                    $values['lat'] = round((float) $m[1], 6);
                    $values['lng'] = round((float) $m[2], 6);
                }
                break;

            default:
                throw new LogicException("Unknown field type {$f['type']}");
        }
    }

    // Cross-field checks
    if (isset($values['price_min'], $values['price_max']) && $values['price_min'] > $values['price_max']) {
        $errors['price_max'] = '"Price to" must be at least "Price from".';
    }

    return ['values' => $values, 'tags' => $tags, 'errors' => $errors, 'form' => $form];
}

/** Check the entity's unique rule; returns an error message or null. */
function admin_unique_error(array $entity, array $values, ?int $id): ?string
{
    if (empty($entity['unique'])) {
        return null;
    }
    [$cols, $message] = $entity['unique'];
    $where = [];
    $params = [];
    foreach ($cols as $c) {
        if ($values[$c] === null) {
            $where[] = "{$c} IS NULL";
        } else {
            $where[] = "{$c} = ?";
            $params[] = $values[$c];
        }
    }
    if ($id !== null) {
        $where[] = 'id <> ?';
        $params[] = $id;
    }
    $stmt = db()->prepare("SELECT 1 FROM {$entity['table']} WHERE " . implode(' AND ', $where) . ' LIMIT 1');
    $stmt->execute($params);
    return $stmt->fetchColumn() ? $message : null;
}

// ---------------------------------------------------------------------
// Persistence
// ---------------------------------------------------------------------

function admin_load(array $entity, int $id): ?array
{
    $stmt = db()->prepare("SELECT * FROM {$entity['table']} WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if ($entity['key'] === 'place') {
        $stmt = db()->prepare('SELECT tag_id FROM rj_place_tags WHERE place_id = ?');
        $stmt->execute([$id]);
        $row['tags'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    return $row;
}

/** Insert or update; returns the row id. */
function admin_save(array $entity, array $values, ?array $tags, ?int $id): int
{
    $cols = array_values(array_intersect(admin_columns($entity), array_keys($values)));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($id === null) {
            foreach ($entity['on_insert'] as $col => $source) {
                if ($source === 'current_user_id') {
                    $cols[] = $col;
                    $values[$col] = current_user()['id'] ?? null;
                }
            }
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $entity['table'],
                implode(', ', $cols),
                implode(', ', array_fill(0, count($cols), '?'))
            );
            $pdo->prepare($sql)->execute(array_map(fn ($c) => $values[$c], $cols));
            $id = (int) $pdo->lastInsertId();
        } else {
            $sql = sprintf(
                'UPDATE %s SET %s WHERE id = ?',
                $entity['table'],
                implode(', ', array_map(fn ($c) => "{$c} = ?", $cols))
            );
            $params = array_map(fn ($c) => $values[$c], $cols);
            $params[] = $id;
            $pdo->prepare($sql)->execute($params);
        }

        if ($tags !== null && $entity['key'] === 'place') {
            $pdo->prepare('DELETE FROM rj_place_tags WHERE place_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO rj_place_tags (place_id, tag_id) VALUES (?, ?)');
            foreach (array_unique($tags) as $tagId) {
                $ins->execute([$id, $tagId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $id;
}

// ---------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------

/** Human label for a raw value shown in list tables. */
function admin_display(string $column, mixed $value): string
{
    if ($value === null || $value === '') {
        return '<span class="muted">—</span>';
    }
    $map = match ($column) {
        'risk_type'      => RJ_RISK_TYPES + ['other' => 'Other'],
        'visa_type'      => RJ_VISA_TYPES,
        'price_level'    => admin_options('price_level'),
        'environment'    => admin_options('environment'),
        'status'         => admin_options('status'),
        default          => null,
    };
    if ($map !== null && isset($map[$value])) {
        $label = $map[$value];
        if ($column === 'status' && $value !== 'published') {
            return '<span class="badge badge--warn">' . e($label) . '</span>';
        }
        return e($label);
    }
    if (in_array($column, ['score', 'cost_index', 'advisory_level'], true)) {
        return '<span class="level level--' . (int) $value . '">' . (int) $value . '</span>';
    }
    return e($value);
}

/** Render one form field (or a section heading). */
function admin_field(array $f, array $form, array $errors): string
{
    if (isset($f['section'])) {
        $hint = isset($f['hint']) ? '<p class="form-section__hint">' . e($f['hint']) . '</p>' : '';
        return '<h3 class="form-section">' . e($f['section']) . '</h3>' . $hint;
    }

    $name = $f['name'];
    $id = 'f_' . $name;
    $value = $form[$name] ?? null;
    $aria = field_aria($errors, $name);
    $req = !empty($f['required']) ? ' required' : '';
    $label = e($f['label']) . (empty($f['required']) && $f['type'] !== 'bool' && $f['type'] !== 'tags'
        ? ' <span class="optional">(optional)</span>' : '');
    $hint = isset($f['hint']) ? '<p class="field__hint">' . e($f['hint']) . '</p>' : '';
    $class = 'field' . (!empty($f['half']) ? ' field--half' : '');

    switch ($f['type']) {
        case 'textarea':
            $input = sprintf('<textarea id="%s" name="%s" rows="%d"%s%s>%s</textarea>',
                $id, e($name), $f['rows'] ?? 5, $req, $aria, e($value ?? ''));
            break;

        case 'select':
            $opts = !empty($f['nullable']) || empty($value) ? '<option value="">' . (!empty($f['nullable']) ? '— None —' : 'Choose…') . '</option>' : '';
            foreach (admin_options($f['options']) as $k => $text) {
                $sel = (string) $k === (string) $value ? ' selected' : '';
                $opts .= sprintf('<option value="%s"%s>%s</option>', e($k), $sel, e($text));
            }
            $input = sprintf('<select id="%s" name="%s"%s%s>%s</select>', $id, e($name), $req, $aria, $opts);
            break;

        case 'bool':
            return sprintf(
                '<div class="%s field--check"><label><input type="checkbox" name="%s" value="1"%s> %s</label>%s%s</div>',
                $class, e($name), !empty($value) ? ' checked' : '', e($f['label']), $hint, field_error($errors, $name)
            );

        case 'tags':
            $boxes = '';
            $selected = array_map('intval', (array) $value);
            foreach (admin_options('tags') as $tagId => $text) {
                $boxes .= sprintf(
                    '<label class="chip"><input type="checkbox" name="tags[]" value="%d"%s> %s</label>',
                    $tagId, in_array((int) $tagId, $selected, true) ? ' checked' : '', e($text)
                );
            }
            return sprintf('<fieldset class="%s"><legend>%s</legend><div class="chips">%s</div>%s</fieldset>',
                $class, e($f['label']), $boxes, $hint);

        default:
            $type = match ($f['type']) {
                'url'     => 'url',
                'date'    => 'date',
                'int'     => 'number',
                default   => 'text',
            };
            $extra = '';
            if ($f['type'] === 'decimal') {
                $extra = ' inputmode="decimal"';
            }
            if ($f['type'] === 'latlng') {
                $extra = ' placeholder="13.7563, 100.5018" inputmode="decimal"';
                $hint = $hint ?: '<p class="field__hint">In Google Maps, right-click the spot and click the numbers to copy them, then paste here.</p>';
            }
            if (isset($f['max']) && in_array($f['type'], ['text'], true)) {
                $extra .= ' maxlength="' . (int) $f['max'] . '"';
            }
            $input = sprintf('<input id="%s" name="%s" type="%s" value="%s"%s%s%s>',
                $id, e($name), $type, e($value ?? ''), $extra, $req, $aria);
    }

    return sprintf('<div class="%s"><label for="%s">%s</label>%s%s%s</div>',
        $class, $id, $label, $input, $hint, field_error($errors, $name));
}

/** Convert a DB row into form values (the reverse of admin_validate). */
function admin_row_to_form(array $entity, array $row): array
{
    $form = [];
    foreach ($entity['fields'] as $f) {
        if (!isset($f['name'])) {
            continue;
        }
        $form[$f['name']] = match ($f['type']) {
            'latlng'  => $row['lat'] !== null ? rtrim(rtrim((string) $row['lat'], '0'), '.') . ', ' . rtrim(rtrim((string) $row['lng'], '0'), '.') : '',
            'decimal' => $row[$f['name']] !== null ? rtrim(rtrim((string) $row[$f['name']], '0'), '.') : '',
            'tags'    => $row['tags'] ?? [],
            default   => $row[$f['name']] ?? '',
        };
    }
    return $form;
}

/** Sub-navigation shown on every admin page. */
function admin_nav(string $active): string
{
    $items = [
        'dashboard' => ['admin/index.php', 'Overview'],
        'country'   => ['admin/list.php?e=country', 'Destinations'],
        'city'      => ['admin/list.php?e=city', 'Cities'],
        'place'     => ['admin/list.php?e=place', 'Places'],
        'safety'    => ['admin/list.php?e=safety', 'Safety tips'],
        'risk'      => ['admin/list.php?e=risk', 'Risk scores'],
        'visa'      => ['admin/list.php?e=visa', 'Visa rules'],
        'users'     => ['admin/users.php', 'Users'],
        'reports'   => ['admin/reports.php', 'Reports'],
    ];
    $openReports = open_report_count();
    $html = '<nav class="admin-nav" aria-label="Admin sections">';
    foreach ($items as $key => [$href, $label]) {
        $badge = $key === 'reports' && $openReports > 0 ? ' <span class="badge badge--warn">' . $openReports . '</span>' : '';
        $html .= sprintf('<a href="%s"%s>%s%s</a>', e(url($href)), $key === $active ? ' aria-current="page"' : '', e($label), $badge);
    }
    return $html . '</nav>';
}
