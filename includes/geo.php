<?php
/**
 * Geography helpers: ISO alpha-3 codes, country outlines (for the tarot card)
 * and finding which destination country a point is in.
 *
 * Outlines and borders come from Natural Earth (public domain) via world-atlas,
 * built with tools/build_shapes.mjs.
 */
declare(strict_types=1);

if (!defined('RJ_BOOT')) { http_response_code(403); exit; }

/** ISO 3166-1 alpha-2 -> alpha-3. */
const RJ_ISO3 = [
    'AD'=>'AND', 'AE'=>'ARE', 'AF'=>'AFG', 'AG'=>'ATG', 'AI'=>'AIA', 'AL'=>'ALB', 'AM'=>'ARM', 'AO'=>'AGO', 'AQ'=>'ATA', 'AR'=>'ARG',
    'AS'=>'ASM', 'AT'=>'AUT', 'AU'=>'AUS', 'AW'=>'ABW', 'AX'=>'ALA', 'AZ'=>'AZE', 'BA'=>'BIH', 'BB'=>'BRB', 'BD'=>'BGD', 'BE'=>'BEL',
    'BF'=>'BFA', 'BG'=>'BGR', 'BH'=>'BHR', 'BI'=>'BDI', 'BJ'=>'BEN', 'BL'=>'BLM', 'BM'=>'BMU', 'BN'=>'BRN', 'BO'=>'BOL', 'BQ'=>'BES',
    'BR'=>'BRA', 'BS'=>'BHS', 'BT'=>'BTN', 'BV'=>'BVT', 'BW'=>'BWA', 'BY'=>'BLR', 'BZ'=>'BLZ', 'CA'=>'CAN', 'CC'=>'CCK', 'CD'=>'COD',
    'CF'=>'CAF', 'CG'=>'COG', 'CH'=>'CHE', 'CI'=>'CIV', 'CK'=>'COK', 'CL'=>'CHL', 'CM'=>'CMR', 'CN'=>'CHN', 'CO'=>'COL', 'CR'=>'CRI',
    'CU'=>'CUB', 'CV'=>'CPV', 'CW'=>'CUW', 'CX'=>'CXR', 'CY'=>'CYP', 'CZ'=>'CZE', 'DE'=>'DEU', 'DJ'=>'DJI', 'DK'=>'DNK', 'DM'=>'DMA',
    'DO'=>'DOM', 'DZ'=>'DZA', 'EC'=>'ECU', 'EE'=>'EST', 'EG'=>'EGY', 'EH'=>'ESH', 'ER'=>'ERI', 'ES'=>'ESP', 'ET'=>'ETH', 'FI'=>'FIN',
    'FJ'=>'FJI', 'FK'=>'FLK', 'FM'=>'FSM', 'FO'=>'FRO', 'FR'=>'FRA', 'GA'=>'GAB', 'GB'=>'GBR', 'GD'=>'GRD', 'GE'=>'GEO', 'GF'=>'GUF',
    'GG'=>'GGY', 'GH'=>'GHA', 'GI'=>'GIB', 'GL'=>'GRL', 'GM'=>'GMB', 'GN'=>'GIN', 'GP'=>'GLP', 'GQ'=>'GNQ', 'GR'=>'GRC', 'GS'=>'SGS',
    'GT'=>'GTM', 'GU'=>'GUM', 'GW'=>'GNB', 'GY'=>'GUY', 'HK'=>'HKG', 'HM'=>'HMD', 'HN'=>'HND', 'HR'=>'HRV', 'HT'=>'HTI', 'HU'=>'HUN',
    'ID'=>'IDN', 'IE'=>'IRL', 'IL'=>'ISR', 'IM'=>'IMN', 'IN'=>'IND', 'IO'=>'IOT', 'IQ'=>'IRQ', 'IR'=>'IRN', 'IS'=>'ISL', 'IT'=>'ITA',
    'JE'=>'JEY', 'JM'=>'JAM', 'JO'=>'JOR', 'JP'=>'JPN', 'KE'=>'KEN', 'KG'=>'KGZ', 'KH'=>'KHM', 'KI'=>'KIR', 'KM'=>'COM', 'KN'=>'KNA',
    'KP'=>'PRK', 'KR'=>'KOR', 'KW'=>'KWT', 'KY'=>'CYM', 'KZ'=>'KAZ', 'LA'=>'LAO', 'LB'=>'LBN', 'LC'=>'LCA', 'LI'=>'LIE', 'LK'=>'LKA',
    'LR'=>'LBR', 'LS'=>'LSO', 'LT'=>'LTU', 'LU'=>'LUX', 'LV'=>'LVA', 'LY'=>'LBY', 'MA'=>'MAR', 'MC'=>'MCO', 'MD'=>'MDA', 'ME'=>'MNE',
    'MF'=>'MAF', 'MG'=>'MDG', 'MH'=>'MHL', 'MK'=>'MKD', 'ML'=>'MLI', 'MM'=>'MMR', 'MN'=>'MNG', 'MO'=>'MAC', 'MP'=>'MNP', 'MQ'=>'MTQ',
    'MR'=>'MRT', 'MS'=>'MSR', 'MT'=>'MLT', 'MU'=>'MUS', 'MV'=>'MDV', 'MW'=>'MWI', 'MX'=>'MEX', 'MY'=>'MYS', 'MZ'=>'MOZ', 'NA'=>'NAM',
    'NC'=>'NCL', 'NE'=>'NER', 'NF'=>'NFK', 'NG'=>'NGA', 'NI'=>'NIC', 'NL'=>'NLD', 'NO'=>'NOR', 'NP'=>'NPL', 'NR'=>'NRU', 'NU'=>'NIU',
    'NZ'=>'NZL', 'OM'=>'OMN', 'PA'=>'PAN', 'PE'=>'PER', 'PF'=>'PYF', 'PG'=>'PNG', 'PH'=>'PHL', 'PK'=>'PAK', 'PL'=>'POL', 'PM'=>'SPM',
    'PN'=>'PCN', 'PR'=>'PRI', 'PS'=>'PSE', 'PT'=>'PRT', 'PW'=>'PLW', 'PY'=>'PRY', 'QA'=>'QAT', 'RE'=>'REU', 'RO'=>'ROU', 'RS'=>'SRB',
    'RU'=>'RUS', 'RW'=>'RWA', 'SA'=>'SAU', 'SB'=>'SLB', 'SC'=>'SYC', 'SD'=>'SDN', 'SE'=>'SWE', 'SG'=>'SGP', 'SH'=>'SHN', 'SI'=>'SVN',
    'SJ'=>'SJM', 'SK'=>'SVK', 'SL'=>'SLE', 'SM'=>'SMR', 'SN'=>'SEN', 'SO'=>'SOM', 'SR'=>'SUR', 'SS'=>'SSD', 'ST'=>'STP', 'SV'=>'SLV',
    'SX'=>'SXM', 'SY'=>'SYR', 'SZ'=>'SWZ', 'TC'=>'TCA', 'TD'=>'TCD', 'TF'=>'ATF', 'TG'=>'TGO', 'TH'=>'THA', 'TJ'=>'TJK', 'TK'=>'TKL',
    'TL'=>'TLS', 'TM'=>'TKM', 'TN'=>'TUN', 'TO'=>'TON', 'TR'=>'TUR', 'TT'=>'TTO', 'TV'=>'TUV', 'TW'=>'TWN', 'TZ'=>'TZA', 'UA'=>'UKR',
    'UG'=>'UGA', 'UM'=>'UMI', 'US'=>'USA', 'UY'=>'URY', 'UZ'=>'UZB', 'VA'=>'VAT', 'VC'=>'VCT', 'VE'=>'VEN', 'VG'=>'VGB', 'VI'=>'VIR',
    'VN'=>'VNM', 'VU'=>'VUT', 'WF'=>'WLF', 'WS'=>'WSM', 'YE'=>'YEM', 'YT'=>'MYT', 'ZA'=>'ZAF', 'ZM'=>'ZMB', 'ZW'=>'ZWE',
];

function iso3(string $iso2): string
{
    return RJ_ISO3[strtoupper($iso2)] ?? strtoupper($iso2);
}

/** Load one of the JSON files in includes/data (cached per request). */
function geo_data(string $name): array
{
    static $cache = [];
    if (!isset($cache[$name])) {
        $raw = @file_get_contents(__DIR__ . '/data/' . $name . '.json');
        $cache[$name] = $raw === false ? [] : (json_decode($raw, true) ?: []);
    }
    return $cache[$name];
}

/** SVG path (200x200 box) of a country's outline, or null if we don't have one. */
function country_shape(string $iso2): ?string
{
    $d = geo_data('shapes')[strtoupper($iso2)] ?? null;
    return is_string($d) ? $d : null;
}

/** Inline SVG silhouette of a country. */
function country_silhouette(string $iso2, string $class = 'silhouette'): string
{
    $d = country_shape($iso2);
    if ($d === null) {
        return '';
    }
    return '<svg class="' . e($class) . '" viewBox="0 0 200 200" aria-hidden="true" focusable="false"><path d="' . e($d) . '"/></svg>';
}

/** Ray-casting point-in-polygon test for one ring of [lng, lat] points. */
function point_in_ring(float $lng, float $lat, array $ring): bool
{
    $inside = false;
    $n = count($ring);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        [$xi, $yi] = $ring[$i];
        [$xj, $yj] = $ring[$j];
        if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
            $inside = !$inside;
        }
    }
    return $inside;
}

/** ISO2 of the destination country containing the point, or null (not one of our destinations). */
function destination_country_at(float $lat, float $lng): ?string
{
    foreach (geo_data('borders') as $iso => $polygons) {
        foreach ($polygons as $rings) {
            if (!$rings || !point_in_ring($lng, $lat, $rings[0])) {
                continue;
            }
            $inHole = false;
            foreach (array_slice($rings, 1) as $hole) {
                if (point_in_ring($lng, $lat, $hole)) {
                    $inHole = true;
                    break;
                }
            }
            if (!$inHole) {
                return $iso;
            }
        }
    }
    return null;
}

/** Roman numeral for the tarot card (1..3999). */
function roman(int $n): string
{
    $map = ['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1];
    $out = '';
    foreach ($map as $r => $v) {
        while ($n >= $v) {
            $out .= $r;
            $n -= $v;
        }
    }
    return $out;
}

/** English name of any country by ISO2 (falls back to the code). */
function country_name_of(?string $iso2): string
{
    foreach (countries_for_select() as $c) {
        if ($c['iso2'] === $iso2) {
            return $c['name_en'];
        }
    }
    return (string) $iso2;
}

/** What each destination calls itself, with the language tag. Skipped when it's the same as the English name. */
const RJ_ENDONYMS = [
    'AU' => null,                         'CH' => ['Schweiz · Suisse · Svizzera', 'de'],
    'CN' => ['中国', 'zh'],                'EG' => ['مصر', 'ar'],
    'FR' => null,                         'GB' => null,
    'GR' => ['Ελλάδα', 'el'],              'ID' => null,
    'IN' => ['भारत', 'hi'],                'IS' => ['Ísland', 'is'],
    'IT' => ['Italia', 'it'],             'JP' => ['日本', 'ja'],
    'KH' => ['កម្ពុជា', 'km'],             'KR' => ['대한민국', 'ko'],
    'PE' => ['Perú', 'es'],               'SG' => ['Singapura · 新加坡 · சிங்கப்பூர்', 'ms'],
    'TH' => ['ประเทศไทย', 'th'],           'TR' => null,
    'US' => null,                         'VN' => ['Việt Nam', 'vi'],
];

/** The country's own name as HTML (empty when it matches the English name or is unknown). */
function endonym_html(string $iso2, string $class): string
{
    $e = RJ_ENDONYMS[strtoupper($iso2)] ?? null;
    return $e ? '<span class="' . e($class) . '" lang="' . e($e[1]) . '">' . e($e[0]) . '</span>' : '';
}
