<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$in = is_post() ? $_POST : $_GET;
$f = read_filters($in);
$tripMode = (string) ($in['trip'] ?? '') === 'trip' ? 'trip' : 'one';
$advantage = (string) ($in['advantage'] ?? '') === '1';
if (is_post() && post_str('imp_visa') === '') {
    $f['imp']['visa'] = 0; // unchecked checkbox
}

$result = null;     // ['places' => [['role' => ?string, 'place' => array]], 'pool' => int]
$options = null;    // two results to choose from (rolling with advantage)
$message = null;
$fromKeep = false;  // result chosen from an advantage roll: no second animation
$scopeLabel = null; // where the draw looked, worked out while the location is still known

/** Place ids this visitor got recently, to avoid repeats. */
function recent_spin_ids(int $limit = 10): array
{
    $user = current_user();
    $stmt = $user
        ? db()->prepare('SELECT place_id FROM rj_random_history WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit)
        : db()->prepare('SELECT place_id FROM rj_random_history WHERE user_id IS NULL AND session_id = ? ORDER BY id DESC LIMIT ' . $limit);
    $stmt->execute([$user ? $user['id'] : session_id()]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Pick one random element, preferring ones not in $avoid. */
function pick(array $pool, array $avoid): ?array
{
    if (!$pool) {
        return null;
    }
    $fresh = array_values(array_filter($pool, fn ($p) => !in_array((int) $p['id'], $avoid, true)));
    $from = $fresh ?: $pool;
    return $from[random_int(0, count($from) - 1)];
}

/** One random result: a single place, or a city with something to see, eat, shop and a place to sleep. */
function draw_result(array $f, string $tripMode, array $avoid): ?array
{
    if ($tripMode === 'one') {
        $pool = filter_strict($f);
        $place = pick($pool, $avoid);
        return $place ? ['places' => [['role' => null, 'place' => $place]], 'pool' => count($pool)] : null;
    }
    $any = filter_strict(array_merge($f, ['group' => '']));
    $cityIds = array_values(array_unique(array_map(fn ($p) => (int) $p['city_id'], $any)));
    if (!$cityIds) {
        return null;
    }
    $cityId = $cityIds[random_int(0, count($cityIds) - 1)];
    $inCity = array_merge($f, ['cities' => [$cityId], 'countries' => []]);
    $picked = [];
    foreach (['sights' => 'See', 'food' => 'Eat and drink', 'shopping' => 'Shop', 'stay' => 'Sleep'] as $group => $role) {
        $p = pick(filter_strict(array_merge($inCity, ['group' => $group])), $avoid);
        if ($p) {
            $picked[] = ['role' => $role, 'place' => $p];
        }
    }
    return $picked ? ['places' => $picked, 'pool' => count($any)] : null;
}

/** Save a result to the visitor's spin history (this is also what collects the card). */
function record_result(array $result, array $f, string $tripMode): void
{
    $ins = db()->prepare('INSERT INTO rj_random_history (user_id, session_id, place_id, filters) VALUES (?, ?, ?, ?)');
    // Location is never stored: drop it before saving what was searched
    $saved = array_diff_key($f, array_flip(['lat', 'lng', 'home', 'home_source']));
    $json = json_encode(['filters' => $saved, 'trip' => $tripMode]);
    foreach ($result['places'] as $item) {
        $ins->execute([current_user()['id'] ?? null, session_id(), $item['place']['id'], $json]);
    }
}

/** Faces of the d20 (ISO alpha-3, deck order) and the face index of each target country. */
function dice_faces(array $targets): array
{
    $codes = deck_order();
    if (count($codes) > 20) {
        $others = array_values(array_diff($codes, $targets));
        $codes = array_merge(array_slice($others, 0, 20 - count(array_unique($targets))), array_unique($targets));
        usort($codes, fn ($a, $b) => strcmp(iso3($a), iso3($b)));
    }
    for ($i = 0; count($codes) < 20; $i++) {
        $codes[] = $codes[$i]; // fewer than 20 destinations: repeat faces
    }
    return [array_map('iso3', $codes), array_map(fn ($t) => array_search($t, $codes, true), $targets)];
}

$noLuck = static function (array $f): string {
    $block = blocking_filter($f);
    return $block
        ? "Nothing fits all of these filters. Clearing \"{$block[0]}\" alone would give {$block[1]} " . ($block[1] === 1 ? 'place' : 'places') . '.'
        : 'Nothing fits all of these filters. Clear a few of them and roll again.';
};

$staleForm = is_post() && post_str('refresh') === '' && !csrf_ok();
if ($staleForm) {
    // The page was left open until the session ended: keep the filters, ask for another roll
    unset($_SESSION['rj_advantage']);
    $message = 'This page was open for a while, so it was refreshed. Your filters are kept: press Roll the dice again.';
} elseif (is_post() && post_str('keep') !== '') {
    // Keeping one of the two cards drawn with advantage
    verify_csrf();
    $pending = $_SESSION['rj_advantage'] ?? null;
    $pick = in_array(post_str('pick'), ['0', '1'], true) ? (int) post_str('pick') : -1; // post_int() starts at 1
    if (is_array($pending) && hash_equals((string) $pending['token'], post_str('keep')) && isset($pending['options'][$pick])) {
        $result = $pending['options'][$pick];
        $f = $pending['filters'];
        $scopeLabel = $pending['label'];
        $tripMode = $pending['trip'];
        $advantage = true;
        record_result($result, $f, $tripMode);
        $fromKeep = true;
    } else {
        $message = 'That draw has expired. Roll again.';
    }
    unset($_SESSION['rj_advantage']);
} elseif (is_post() && post_str('refresh') === '') {
    verify_csrf();
    $avoid = recent_spin_ids();
    $first = draw_result($f, $tripMode, $avoid);
    if ($first && $advantage) {
        // A second, different draw: another country if possible, otherwise another place
        $firstIso = $first['places'][0]['place']['iso2'];
        $firstIds = array_map(fn ($i) => (int) $i['place']['id'], $first['places']);
        $second = null;
        for ($try = 0; $try < 8; $try++) {
            $candidate = draw_result($f, $tripMode, array_merge($avoid, $firstIds));
            if (!$candidate || array_intersect($firstIds, array_map(fn ($i) => (int) $i['place']['id'], $candidate['places']))) {
                continue;
            }
            $second = $candidate;
            if ($candidate['places'][0]['place']['iso2'] !== $firstIso) {
                break;
            }
        }
        if ($second) {
            $options = [$first, $second];
            $_SESSION['rj_advantage'] = ['token' => bin2hex(random_bytes(16)), 'options' => $options,
                'filters' => array_merge($f, ['lat' => null, 'lng' => null]), 'label' => scope_label($f), 'trip' => $tripMode];
        } else {
            $result = $first; // only one possible result: no choice to make
            record_result($result, $f, $tripMode);
        }
    } elseif ($first) {
        $result = $first;
        record_result($result, $f, $tripMode);
    } else {
        $message = $noLuck($f);
    }
}

// Post/Redirect/Get: keep the outcome in the session and show it on a plain GET page, so Back
// and Refresh never ask to resend the form (or roll again). Location is never kept: the
// distances are already worked out, and the page puts the browser's own location back in.
$animate = true;
if (is_post()) {
    $keptFilters = array_merge($f, ['lat' => null, 'lng' => null]);
    if ($keptFilters['home_source'] === 'location') {
        $keptFilters['home'] = $keptFilters['home_source'] = null;
    }
    $spinId = 's' . bin2hex(random_bytes(6));
    $spins = $_SESSION['rj_spin'] ?? [];
    $spins[$spinId] = [
        'filters' => $keptFilters, 'trip' => $tripMode, 'advantage' => $advantage,
        'result' => $result, 'options' => $options, 'token' => $options ? $_SESSION['rj_advantage']['token'] : null,
        'message' => $message, 'stale' => $staleForm, 'from_keep' => $fromKeep, 'shown' => false,
        'label' => $scopeLabel ?? scope_label($f),
    ];
    $_SESSION['rj_spin'] = array_slice($spins, -6, null, true);
    redirect('random.php?r=' . $spinId . ($result || $options || $message ? '#result' : ''));
}
$spinId = is_string($_GET['r'] ?? null) ? $_GET['r'] : '';
$spin = $spinId !== '' ? ($_SESSION['rj_spin'][$spinId] ?? null) : null;
if (is_array($spin)) {
    $f = $spin['filters'];
    $tripMode = $spin['trip'];
    $advantage = $spin['advantage'];
    $result = $spin['result'];
    $options = $spin['options'];
    $message = $spin['message'];
    $staleForm = $spin['stale'];
    $fromKeep = $spin['from_keep'];
    $scopeLabel = $spin['label'];
    $animate = !$spin['shown']; // the dice rolls once; coming Back shows the card straight away
    $_SESSION['rj_spin'][$spinId]['shown'] = true;
} elseif ($spinId !== '') {
    $message = 'That roll is no longer available. Your filters may have been cleared: roll the dice again.';
    $staleForm = true;
}
$advantageToken = $options ? (string) ($spin['token'] ?? '') : '';
$rolls = $animate && !$fromKeep;

// Recent spins for this visitor
$user = current_user();
$stmt = db()->prepare(
    'SELECT h.created_at, p.id, p.name_en, ci.name_en AS city_name, co.iso2
     FROM rj_random_history h
     JOIN rj_places p ON p.id = h.place_id
     JOIN rj_cities ci ON ci.id = p.city_id
     JOIN rj_countries co ON co.id = ci.country_id
     WHERE ' . ($user ? 'h.user_id = ?' : 'h.user_id IS NULL AND h.session_id = ?') . '
     ORDER BY h.id DESC LIMIT 8'
);
$stmt->execute([$user ? $user['id'] : session_id()]);
$history = $stmt->fetchAll();

$first = $result['places'][0]['place'] ?? null;
$dice = null;
if ($first) {
    $dice = dice_faces([$first['iso2']]);
} elseif ($options) {
    $dice = dice_faces([$options[0]['places'][0]['place']['iso2'], $options[1]['places'][0]['place']['iso2']]);
}
$mapPoints = $result ? array_map(fn ($i) => [
    'lat' => (float) $i['place']['lat'], 'lng' => (float) $i['place']['lng'], 'name' => $i['place']['name_en'],
    'note' => $i['role'] ?? $i['place']['category_name'], 'url' => place_url((int) $i['place']['id']), 'kind' => $i['place']['category_slug'],
], $result['places']) : [];

$pageTitle = 'Random trip';
$bodyClass = 'page-spin';
$useMap = (bool) $result;
$mode = 'random';
require __DIR__ . '/includes/header.php';

/** Hidden inputs that carry the current filters into the lock / roll-again forms. */
$carry = static function (array $f, string $trip, bool $advantage, array $override = []) {
    $f = array_merge($f, $override);
    $html = csrf_field() . '<input type="hidden" name="trip" value="' . e($trip) . '">';
    if ($advantage) {
        $html .= '<input type="hidden" name="advantage" value="1">';
    }
    foreach (['group', 'max_price', 'max_risk', 'environment', 'cost', 'radius'] as $k) {
        if ($f[$k] !== null && $f[$k] !== '') {
            $html .= '<input type="hidden" name="' . $k . '" value="' . e((string) $f[$k]) . '">';
        }
    }
    // Filled in by the page from this browser's location, when it is switched on
    $html .= '<input type="hidden" name="lat" value="" data-loc-lat><input type="hidden" name="lng" value="" data-loc-lng>';
    if ($f['not_home']) {
        $html .= '<input type="hidden" name="not_home" value="1">';
    }
    if ($f['home_source'] === 'chosen') {
        $html .= '<input type="hidden" name="home" value="' . e($f['home']) . '">';
    }
    foreach (['countries', 'cities', 'terrain', 'themes'] as $k) {
        foreach ($f[$k] as $v) {
            $html .= '<input type="hidden" name="' . $k . '[]" value="' . e((string) $v) . '">';
        }
    }
    if ($f['imp']['visa'] === 3) {
        $html .= '<input type="hidden" name="imp_visa" value="3">';
    }
    return $html;
};

/** Headline of a result: the place, or the city for a whole day. */
$headline = static fn (array $r, string $trip): array => $trip === 'trip'
    ? [$r['places'][0]['place']['city_name'], $r['places'][0]['place']['country_name']]
    : [$r['places'][0]['place']['name_en'], $r['places'][0]['place']['city_name'] . ', ' . $r['places'][0]['place']['country_name']];

$diceStage = static function (string $hint): string {
    return '<div class="oracle__stage" data-dice-stage hidden>'
        . '<canvas class="oracle__canvas" data-dice-canvas aria-hidden="true"></canvas>'
        . '<p class="oracle__hint" data-dice-hint>' . e($hint) . ' <button type="button" class="link-button" data-dice-skip>Skip</button></p>'
        . '</div>';
};
?>
<header class="tool-head">
    <h1 class="page-title">Roll for a journey</h1>
    <p class="tool-head__lead">Can't decide? Set the limits you care about and let the twenty-sided dice choose.
        Each country you draw adds its card to <a href="<?= e(url('deck.php')) ?>">your deck</a>.</p>
</header>

<div class="tool-layout<?= $result || $options || $message ? ' has-result' : '' ?>">
    <form class="tool-form" method="post" action="<?= e(url('random.php') . '#result') ?>">
        <?= csrf_field() ?>
        <fieldset class="filter-group">
            <legend>What to draw</legend>
            <div class="segmented segmented--wide" role="radiogroup" aria-label="What to draw">
                <label><input type="radio" name="trip" value="one"<?= $tripMode === 'one' ? ' checked' : '' ?> data-autosubmit> One place</label>
                <label><input type="radio" name="trip" value="trip"<?= $tripMode === 'trip' ? ' checked' : '' ?> data-autosubmit> A whole day</label>
            </div>
            <p class="field__hint"><?= $tripMode === 'trip'
                ? 'Picks a city, then something to see, eat, shop and a place to stay there.'
                : 'Picks a single place that fits your limits.' ?></p>
            <div class="field field--check-inline">
                <label><input type="checkbox" name="advantage" value="1"<?= $advantage ? ' checked' : '' ?>>
                    Roll with advantage: draw two cards and keep one</label>
            </div>
        </fieldset>
        <?php require __DIR__ . '/includes/filter_form.php'; ?>
        <button type="submit" class="button button--wide button--spin">Roll the dice</button>
    </form>

    <section class="tool-results" id="result" aria-labelledby="spin-title" aria-live="polite">
        <?php if ($message): ?>
            <h2 id="spin-title" class="section-title"><?= str_starts_with($message, 'Nothing fits') ? 'No luck this time' : 'Ready when you are' ?></h2>
            <p><?= e($message) ?></p>

        <?php elseif ($options): ?>
            <h2 id="spin-title" class="visually-hidden">Choose one of two cards</h2>
            <div class="oracle oracle--pair<?= $rolls ? '' : ' is-static' ?>" data-oracle
                 data-faces="<?= e(json_encode($dice[0])) ?>" data-targets="<?= e(json_encode($dice[1])) ?>"
                 <?= $rolls ? 'data-dice-src="' . e(asset('js/dice.js')) . '"' : '' ?>>
                <?= $rolls ? $diceStage('The dice are rolling…') : '' ?>
                <?php foreach ($options as $i => $opt): $p0 = $opt['places'][0]['place']; [$title, $sub] = $headline($opt, $tripMode); ?>
                    <div class="oracle__choice oracle__choice--<?= $i === 0 ? 'a' : 'b' ?>">
                        <?= tarot_card($p0['iso2'], ['attrs' => ' data-tarot']) ?>
                        <div class="oracle__reading">
                            <p class="oracle__title oracle__title--small"><?= e($title) ?></p>
                            <p class="ticket__sub"><?= e($sub) ?><?php if ($p0['distance'] !== null): ?> · <?= e(format_distance($p0['distance'])) ?> from you<?php endif; ?></p>
                            <form method="post" action="<?= e(url('random.php') . '#result') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="keep" value="<?= e($advantageToken) ?>">
                                <input type="hidden" name="pick" value="<?= $i ?>">
                                <button type="submit" class="button">Keep this card</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="muted small">Only the card you keep goes into your deck and spin history.</p>

        <?php elseif (!$result): ?>
            <h2 id="spin-title" class="visually-hidden">Result</h2>
            <div class="empty-state">
                <p class="empty-state__title">Roll the twenty-sided dice to draw your destination.</p>
                <p>Each face is one of our <?= count(destination_list()) ?> countries. Leave everything empty for a completely random pick from all <?= (int) db()->query("SELECT COUNT(*) FROM rj_places WHERE status = 'published'")->fetchColumn() ?> places.</p>
            </div>

        <?php else: ?>
            <h2 id="spin-title" class="visually-hidden">Your spin</h2>
            <?php $c = find_country($first['iso2']); [$title, $sub] = $headline($result, $tripMode); ?>
            <div class="oracle<?= $rolls ? '' : ' is-static' ?>" data-oracle
                 data-faces="<?= e(json_encode($dice[0])) ?>" data-targets="<?= e(json_encode($dice[1])) ?>"
                 <?= $rolls ? 'data-dice-src="' . e(asset('js/dice.js')) . '"' : '' ?>>
                <?= $rolls ? $diceStage('The dice is rolling…') : '' ?>
                <?= tarot_card($first['iso2'], ['attrs' => ' data-tarot']) ?>
                <div class="oracle__reading" data-reading>
                    <p class="ticket__label">The dice has spoken. You're going to</p>
                    <p class="oracle__title"><?= e($title) ?></p>
                    <p class="ticket__sub"><?= e($sub) ?>
                        <?php if ($first['distance'] !== null): ?>· <?= e(format_distance($first['distance'])) ?> from you<?php endif; ?></p>
                    <?php if (!empty($c['summary'])): ?><p class="oracle__summary"><?= e($c['summary']) ?></p><?php endif; ?>
                    <p class="ticket__pool small">Picked from <?= (int) $result['pool'] ?> <?= $result['pool'] === 1 ? 'place' : 'places' ?> that fit your limits
                        (<?= e($scopeLabel ?? scope_label($f)) ?>).</p>
                    <p class="oracle__actions">
                        <button type="button" class="button button--small button--ghost" data-save-card="<?= card_data_attr($first['iso2'], $title . ' · ' . $sub) ?>" hidden>Save card as image</button>
                        <a class="button button--small button--ghost" href="<?= e(url('deck.php')) ?>">See your deck</a>
                    </p>
                </div>
            </div>

            <?php $thumbs = first_photos(array_map(fn ($i) => $i['place']['id'], $result['places'])); ?>
            <ul class="trip-list" data-reading>
                <?php foreach ($result['places'] as $item): $p = $item['place']; $ph = $thumbs[(int) $p['id']] ?? null; ?>
                    <li class="card<?= $ph ? ' card--photo' : '' ?>">
                        <?php if ($ph): ?><a class="card__photo" href="<?= e(place_url((int) $p['id'])) ?>" tabindex="-1" aria-hidden="true"><?= photo_img($ph, 'thumb', '') ?></a><div class="card__body"><?php endif; ?>
                        <?php if ($item['role']): ?><p class="trip-list__role"><?= e($item['role']) ?></p><?php endif; ?>
                        <h3 class="card__title"><a href="<?= e(place_url((int) $p['id'])) ?>"><?= e($p['name_en']) ?></a></h3>
                        <p class="card__text"><?= e($p['summary'] ?? '') ?></p>
                        <p class="card__meta"><?= e($p['category_name']) ?>, <?= e(RJ_PRICE_WORDS[(int) $p['price_level']]) ?>,
                            risk <?= $p['overall_risk'] !== null ? number_format((float) $p['overall_risk'], 1) . '/5' : 'not rated' ?><?php
                            if ($p['distance'] !== null): ?>, <?= e(format_distance($p['distance'])) ?> from you<?php endif; ?></p>
                        <?php if ($ph): ?></div><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?= map_block('map-spin', $mapPoints, 'Map of your spin', 13, $tripMode === 'trip') ?>

            <div class="spin-actions">
                <form method="post" action="<?= e(url('random.php') . '#result') ?>" class="inline-form">
                    <?= $carry($f, $tripMode, $advantage) ?>
                    <button type="submit" class="button">Roll again</button>
                </form>
                <form method="post" action="<?= e(url('random.php') . '#result') ?>" class="inline-form">
                    <?= $carry($f, $tripMode, $advantage, ['countries' => [$first['iso2']], 'cities' => [], 'not_home' => false]) ?>
                    <button type="submit" class="button button--quiet">Keep <?= e($first['country_name']) ?>, roll again</button>
                </form>
                <form method="post" action="<?= e(url('random.php') . '#result') ?>" class="inline-form">
                    <?= $carry($f, $tripMode, $advantage, ['countries' => [$first['iso2']], 'cities' => [(int) $first['city_id']], 'not_home' => false]) ?>
                    <button type="submit" class="button button--quiet">Keep <?= e($first['city_name']) ?>, roll again</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($history): ?>
            <section class="section" aria-labelledby="history-title">
                <h2 id="history-title" class="section-title">Your recent spins</h2>
                <ul class="nearby-list">
                    <?php foreach ($history as $h): ?>
                        <li><a href="<?= e(place_url((int) $h['id'])) ?>"><?= e($h['name_en']) ?></a>
                            <span class="muted"><?= flag_img($h['iso2'], 16) ?> <?= e($h['city_name']) ?>, <?= e(date('j M H:i', strtotime($h['created_at']))) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
