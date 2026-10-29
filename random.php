<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$f = read_filters(is_post() ? $_POST : $_GET);
$tripMode = (is_post() ? post_str('trip') : (string) ($_GET['trip'] ?? '')) === 'trip' ? 'trip' : 'one';
if (is_post() && post_str('imp_visa') === '') {
    $f['imp']['visa'] = 0; // unchecked checkbox
}

$result = null;     // ['places' => [...], 'city' => ..., 'pool' => int]
$message = null;

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

/** Pick one random element, preferring ones not seen recently. */
function pick(array $pool, array $avoid): ?array
{
    if (!$pool) {
        return null;
    }
    $fresh = array_values(array_filter($pool, fn ($p) => !in_array((int) $p['id'], $avoid, true)));
    $from = $fresh ?: $pool;
    return $from[random_int(0, count($from) - 1)];
}

$spin = is_post() && post_str('refresh') === '';
if ($spin) {
    verify_csrf();
    $avoid = recent_spin_ids();

    if ($tripMode === 'one') {
        $pool = filter_strict($f);
        $place = pick($pool, $avoid);
        if ($place) {
            $result = ['places' => [['role' => null, 'place' => $place]], 'pool' => count($pool), 'decoys' => array_column($pool, 'name_en')];
        }
    } else {
        // Whole trip: random city first, then one place of each kind in that city
        $any = filter_strict(array_merge($f, ['group' => '']));
        $cityIds = array_values(array_unique(array_map(fn ($p) => (int) $p['city_id'], $any)));
        if ($cityIds) {
            $cityId = $cityIds[random_int(0, count($cityIds) - 1)];
            $inCity = array_merge($f, ['city_id' => $cityId]);
            $picked = [];
            foreach (['sights' => 'See', 'food' => 'Eat and drink', 'shopping' => 'Shop', 'stay' => 'Sleep'] as $group => $role) {
                $p = pick(filter_strict(array_merge($inCity, ['group' => $group])), $avoid);
                if ($p) {
                    $picked[] = ['role' => $role, 'place' => $p];
                }
            }
            $result = ['places' => $picked, 'pool' => count($any), 'decoys' => array_values(array_unique(array_column($any, 'city_name')))];
        }
    }

    if ($result && $result['places']) {
        $ins = db()->prepare('INSERT INTO rj_random_history (user_id, session_id, place_id, filters) VALUES (?, ?, ?, ?)');
        $json = json_encode(['filters' => $f, 'trip' => $tripMode]);
        foreach ($result['places'] as $item) {
            $ins->execute([current_user()['id'] ?? null, session_id(), $item['place']['id'], $json]);
        }
    } else {
        $result = null;
        $block = blocking_filter($f);
        $message = $block
            ? "Nothing fits all of these filters. Clearing \"{$block[0]}\" alone would give {$block[1]} " . ($block[1] === 1 ? 'place' : 'places') . '.'
            : 'Nothing fits all of these filters. Clear a few of them and spin again.';
    }
}

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
$mapPoints = $result ? array_map(fn ($i) => [
    'lat' => (float) $i['place']['lat'], 'lng' => (float) $i['place']['lng'], 'name' => $i['place']['name_en'],
    'note' => $i['role'] ?? $i['place']['category_name'], 'url' => place_url((int) $i['place']['id']), 'kind' => $i['place']['category_slug'],
], $result['places']) : [];

$pageTitle = 'Random trip';
$useMap = (bool) $result;
$mode = 'random';
require __DIR__ . '/includes/header.php';

/** Hidden inputs that carry the current filters into the lock / spin-again forms. */
$carry = static function (array $f, string $trip, array $override = []) {
    $f = array_merge($f, $override);
    $html = csrf_field() . '<input type="hidden" name="trip" value="' . e($trip) . '">';
    foreach (['country', 'city_id', 'group', 'max_price', 'max_risk', 'environment', 'cost'] as $k) {
        if ($f[$k] !== null && $f[$k] !== '') {
            $html .= '<input type="hidden" name="' . $k . '" value="' . e($f[$k]) . '">';
        }
    }
    foreach (['terrain', 'themes'] as $k) {
        foreach ($f[$k] as $v) {
            $html .= '<input type="hidden" name="' . $k . '[]" value="' . e($v) . '">';
        }
    }
    if ($f['imp']['visa'] === 3) {
        $html .= '<input type="hidden" name="imp_visa" value="3">';
    }
    return $html;
};
?>
<header class="tool-head">
    <h1 class="page-title">Spin for a trip</h1>
    <p class="tool-head__lead">Can't decide? Set the limits you care about and let chance pick. Like what you got?
        Keep the country or city and spin again for more ideas there.</p>
</header>

<div class="tool-layout<?= $result || $message ? ' has-result' : '' ?>">
    <form class="tool-form" method="post" action="<?= e(url('random.php') . '#result') ?>">
        <?= csrf_field() ?>
        <fieldset class="filter-group">
            <legend>What to spin</legend>
            <div class="segmented segmented--wide" role="radiogroup" aria-label="What to spin">
                <label><input type="radio" name="trip" value="one"<?= $tripMode === 'one' ? ' checked' : '' ?> data-autosubmit> One place</label>
                <label><input type="radio" name="trip" value="trip"<?= $tripMode === 'trip' ? ' checked' : '' ?> data-autosubmit> A whole day</label>
            </div>
            <p class="field__hint"><?= $tripMode === 'trip'
                ? 'Picks a city, then something to see, eat, shop and a place to stay there.'
                : 'Picks a single place that fits your limits.' ?></p>
        </fieldset>
        <?php require __DIR__ . '/includes/filter_form.php'; ?>
        <button type="submit" class="button button--wide button--spin">Spin</button>
    </form>

    <section class="tool-results" id="result" aria-labelledby="spin-title" aria-live="polite">
        <?php if ($message): ?>
            <h2 id="spin-title" class="section-title">No luck this time</h2>
            <p><?= e($message) ?></p>
        <?php elseif (!$result): ?>
            <h2 id="spin-title" class="visually-hidden">Result</h2>
            <div class="empty-state">
                <p class="empty-state__title">Press Spin to get a destination.</p>
                <p>Leave everything empty for a completely random pick from all <?= (int) db()->query("SELECT COUNT(*) FROM rj_places WHERE status = 'published'")->fetchColumn() ?> places.</p>
            </div>
        <?php else: ?>
            <h2 id="spin-title" class="visually-hidden">Your spin</h2>
            <div class="ticket">
                <p class="ticket__label">You're going to</p>
                <p class="ticket__city" data-reveal data-decoys="<?= e(json_encode(array_slice($result['decoys'], 0, 30), JSON_UNESCAPED_UNICODE)) ?>">
                    <?= flag_img($first['iso2'], 32) ?>
                    <span data-reveal-text><?= e($tripMode === 'trip' ? $first['city_name'] : $first['name_en']) ?></span>
                </p>
                <p class="ticket__sub"><?= e($tripMode === 'trip' ? $first['country_name'] : $first['city_name'] . ', ' . $first['country_name']) ?></p>
                <p class="ticket__pool small">Picked from <?= (int) $result['pool'] ?> <?= $result['pool'] === 1 ? 'place' : 'places' ?> that fit your limits.</p>
            </div>

            <ul class="trip-list">
                <?php foreach ($result['places'] as $item): $p = $item['place']; ?>
                    <li class="card">
                        <?php if ($item['role']): ?><p class="trip-list__role"><?= e($item['role']) ?></p><?php endif; ?>
                        <h3 class="card__title"><a href="<?= e(place_url((int) $p['id'])) ?>"><?= e($p['name_en']) ?></a></h3>
                        <?php if ($p['name_th']): ?><p class="card__local" lang="th"><?= e($p['name_th']) ?></p><?php endif; ?>
                        <p class="card__text"><?= e($p['summary'] ?? '') ?></p>
                        <p class="card__meta"><?= e($p['category_name']) ?>, <?= e(RJ_PRICE_WORDS[(int) $p['price_level']]) ?>,
                            risk <?= $p['overall_risk'] !== null ? number_format((float) $p['overall_risk'], 1) . '/5' : 'not rated' ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?= map_block('map-spin', $mapPoints, 'Map of your spin', 13) ?>

            <div class="spin-actions">
                <form method="post" action="<?= e(url('random.php') . '#result') ?>" class="inline-form">
                    <?= $carry($f, $tripMode) ?>
                    <button type="submit" class="button">Spin again</button>
                </form>
                <form method="post" action="<?= e(url('random.php') . '#result') ?>" class="inline-form">
                    <?= $carry($f, $tripMode, ['country' => $first['iso2'], 'city_id' => null]) ?>
                    <button type="submit" class="button button--quiet">Keep <?= e($first['country_name']) ?>, spin again</button>
                </form>
                <form method="post" action="<?= e(url('random.php') . '#result') ?>" class="inline-form">
                    <?= $carry($f, $tripMode, ['country' => $first['iso2'], 'city_id' => (int) $first['city_id']]) ?>
                    <button type="submit" class="button button--quiet">Keep <?= e($first['city_name']) ?>, spin again</button>
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
