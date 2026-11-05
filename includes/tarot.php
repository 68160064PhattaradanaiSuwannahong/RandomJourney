<?php
/**
 * The destination deck: one tarot card per destination country.
 *
 * The deck is ordered by ISO alpha-3 code, the same order as the faces of the
 * twenty-sided dice, so a country always has the same numeral (Japan is XII).
 * A card is "collected" once the dice has landed on that country for you.
 */
declare(strict_types=1);

if (!defined('RJ_BOOT')) { http_response_code(403); exit; }

/** Destination ISO2 codes in deck order. */
function deck_order(): array
{
    static $codes = null;
    if ($codes === null) {
        $codes = array_keys(destination_list());
        usort($codes, fn ($a, $b) => strcmp(iso3($a), iso3($b)));
    }
    return $codes;
}

/** Roman numeral of a country's card, e.g. "XII". */
function card_numeral(string $iso2): string
{
    $i = array_search(strtoupper($iso2), deck_order(), true);
    return $i === false ? '' : roman($i + 1);
}

/** ISO2 codes of the countries the current visitor has drawn (user account, or this browser session for guests). */
function collected_countries(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $user = current_user();
    $stmt = db()->prepare(
        'SELECT DISTINCT co.iso2
         FROM rj_random_history h
         JOIN rj_places p ON p.id = h.place_id
         JOIN rj_cities ci ON ci.id = p.city_id
         JOIN rj_countries co ON co.id = ci.country_id
         WHERE ' . ($user ? 'h.user_id = ?' : 'h.user_id IS NULL AND h.session_id = ?')
    );
    $stmt->execute([$user ? $user['id'] : session_id()]);
    return $cache = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * HTML of a tarot card.
 * Options: 'up' (face up, default true), 'size' ('' or 'sm'), 'link' (URL around the card), 'tilt' (follows the pointer),
 *          'attrs' (extra attributes for the <article>), 'label' (accessible name).
 */
function tarot_card(string $iso2, array $opt = []): string
{
    $iso2 = strtoupper($iso2);
    $up = $opt['up'] ?? true;
    $name = destination_list()[$iso2] ?? country_name_of($iso2);
    $classes = 'tarot' . (!empty($opt['size']) ? ' tarot--' . $opt['size'] : '') . ($up ? '' : ' tarot--down')
             . (!empty($opt['tilt']) ? ' tarot--tilt' : '');
    $label = $opt['label'] ?? ($up ? "Card " . card_numeral($iso2) . ": $name" : 'A card you haven\'t drawn yet');

    $face = '<p class="tarot__numeral">' . e(card_numeral($iso2)) . '</p>'
        . '<div class="tarot__art">'
        . '<svg class="tarot__rays" viewBox="0 0 200 200" aria-hidden="true" focusable="false">'
        . '<circle cx="100" cy="100" r="92"/><circle cx="100" cy="100" r="70"/>'
        . '<path d="M100 2v30M100 168v30M2 100h30M168 100h30M31 31l21 21M148 148l21 21M169 31l-21 21M52 148l-21 21"/></svg>'
        . country_silhouette($iso2, 'tarot__shape')
        . '</div>'
        . '<p class="tarot__flag">' . flag_img($iso2, 48) . '</p>'
        . '<h3 class="tarot__name">' . e($name) . '</h3>'
        . (($local = endonym_html($iso2, 'tarot__name-th')) ? '<p class="tarot__local">' . $local . '</p>' : '')
        . '<p class="tarot__code">' . e(iso3($iso2)) . '</p>';

    $html = '<article class="' . e($classes) . '" aria-label="' . e($label) . '"' . ($opt['attrs'] ?? '') . '>'
        . '<div class="tarot__inner">'
        . '<div class="tarot__face"' . ($up ? '' : ' aria-hidden="true"') . '>' . ($up ? $face : '') . '</div>'
        . '<div class="tarot__back" aria-hidden="true"></div>'
        . '</div></article>';

    return !empty($opt['link']) ? '<a class="tarot-link" href="' . e($opt['link']) . '">' . $html . '</a>' : $html;
}

/** Data for the "Save card" image (read by app.js). */
function card_data_attr(string $iso2, string $placeLine): string
{
    $iso2 = strtoupper($iso2);
    $e = RJ_ENDONYMS[$iso2] ?? null;
    return e(json_encode([
        'numeral' => card_numeral($iso2),
        'name'    => destination_list()[$iso2] ?? $iso2,
        'local'   => $e[0] ?? '',
        'code'    => iso3($iso2),
        'shape'   => country_shape($iso2) ?? '',
        'flag'    => 'https://flagcdn.com/w160/' . strtolower($iso2) . '.png',
        'place'   => $placeLine,
    ], JSON_UNESCAPED_UNICODE));
}
