<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$tripId = is_post() ? (int) post_int('trip_id') : (int) filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$trip = $tripId ? find_trip($tripId, (int) $user['id']) : null;
if ($trip === null) {
    not_found('trip');
}

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    $placeId = post_int('place_id');
    $itemId = post_int('item_id');
    $day = post_int('day');
    $back = 'trip.php?id=' . (int) $trip['id'];

    $item = null;
    if ($itemId !== null) {
        $stmt = db()->prepare('SELECT * FROM rj_trip_items WHERE id = ? AND trip_id = ?');
        $stmt->execute([$itemId, $trip['id']]);
        $item = $stmt->fetch() ?: null;
    }

    switch ($action) {
        case 'add':
            $day = max(1, min((int) $trip['days'], $day ?? 1));
            $count = (int) db()->query('SELECT COUNT(*) FROM rj_trip_items WHERE trip_id = ' . (int) $trip['id'])->fetchColumn();
            if ($placeId !== null && find_place($placeId) && $count < RJ_TRIP_MAX_ITEMS) {
                $ins = db()->prepare('INSERT IGNORE INTO rj_trip_items (trip_id, place_id, day_no, sort_order) VALUES (?, ?, ?, ?)');
                $ins->execute([$trip['id'], $placeId, $day, trip_next_sort((int) $trip['id'], $day)]);
                flash($ins->rowCount() ? 'success' : 'info', $ins->rowCount() ? "Added to day $day of \"{$trip['title']}\"." : 'That place is already in this trip.');
                touch_trip((int) $trip['id']);
            }
            if (post_str('back') === 'place' && $placeId !== null) {
                $back = 'place.php?id=' . $placeId;
            }
            break;

        case 'remove':
            if ($item) {
                db()->prepare('DELETE FROM rj_trip_items WHERE id = ?')->execute([$item['id']]);
                touch_trip((int) $trip['id']);
            }
            break;

        case 'move':
            // To another day (goes to the end of that day)
            if ($item && $day !== null && $day >= 1 && $day <= (int) $trip['days'] && $day !== (int) $item['day_no']) {
                db()->prepare('UPDATE rj_trip_items SET day_no = ?, sort_order = ? WHERE id = ?')
                    ->execute([$day, trip_next_sort((int) $trip['id'], $day), $item['id']]);
                touch_trip((int) $trip['id']);
            }
            break;

        case 'up':
        case 'down':
            if ($item) {
                // Renumber the day, then swap with the neighbour
                $stmt = db()->prepare('SELECT id FROM rj_trip_items WHERE trip_id = ? AND day_no = ? ORDER BY sort_order, id');
                $stmt->execute([$trip['id'], $item['day_no']]);
                $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                $pos = array_search((int) $item['id'], $ids, true);
                $swap = $action === 'up' ? $pos - 1 : $pos + 1;
                if ($pos !== false && isset($ids[$swap])) {
                    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
                    $upd = db()->prepare('UPDATE rj_trip_items SET sort_order = ? WHERE id = ?');
                    foreach ($ids as $i => $id) {
                        $upd->execute([$i, $id]);
                    }
                    touch_trip((int) $trip['id']);
                }
            }
            break;

        case 'settings':
            $title = post_str('title');
            $days = filter_var(post_str('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => RJ_TRIP_MAX_DAYS]]);
            if ($title !== '' && mb_strlen($title) <= 120 && $days !== false) {
                db()->prepare('UPDATE rj_trips SET title = ?, days = ? WHERE id = ?')->execute([$title, $days, $trip['id']]);
                // Places on days that no longer exist move to the last day
                db()->prepare('UPDATE rj_trip_items SET day_no = ? WHERE trip_id = ? AND day_no > ?')->execute([$days, $trip['id'], $days]);
                flash('success', 'Trip updated.');
            } else {
                flash('error', 'Give the trip a name and between 1 and ' . RJ_TRIP_MAX_DAYS . ' days.');
            }
            break;
    }
    redirect($back);
}

$items = trip_items((int) $trip['id']);
$byDay = array_fill(1, (int) $trip['days'], []);
foreach ($items as $p) {
    $byDay[(int) $p['day_no']][] = $p;
}
$currency = display_currency();
[$lo, $hi, $unknown] = trip_budget($items, $currency);
$thumbs = first_photos(array_column($items, 'id'));

// Saved places that aren't in this trip yet
$stmt = db()->prepare(
    "SELECT p.id, p.name_en, ci.name_en AS city_name, co.iso2
     FROM rj_favorites f
     JOIN rj_places p ON p.id = f.place_id
     JOIN rj_cities ci ON ci.id = p.city_id
     JOIN rj_countries co ON co.id = ci.country_id
     WHERE f.user_id = ? AND p.status = 'published'
       AND p.id NOT IN (SELECT place_id FROM rj_trip_items WHERE trip_id = ?)
     ORDER BY co.name_en, ci.name_en, p.name_en"
);
$stmt->execute([$user['id'], $trip['id']]);
$saved = $stmt->fetchAll();

$mapPoints = array_map(fn ($p) => [
    'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'], 'name' => $p['name_en'],
    'note' => 'Day ' . (int) $p['day_no'] . ' · ' . $p['category_name'], 'url' => place_url((int) $p['id']), 'kind' => $p['category_slug'],
], $items);

$dayDate = static function (int $day) use ($trip): string {
    return $trip['start_date'] ? date('D j M', strtotime($trip['start_date'] . ' +' . ($day - 1) . ' days')) : '';
};
$hidden = static fn (array $fields): string => csrf_field() . '<input type="hidden" name="trip_id" value="' . (int) $trip['id'] . '">'
    . implode('', array_map(fn ($k, $v) => '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">', array_keys($fields), $fields));

$pageTitle = $trip['title'];
$useMap = (bool) $items;
require __DIR__ . '/includes/header.php';
?>
<?= breadcrumb([['Your trips', url('trips.php')], [$trip['title'], null]]) ?>
<header class="tool-head trip-head">
    <h1 class="page-title"><?= e($trip['title']) ?></h1>
    <p class="tool-head__lead"><?= (int) $trip['days'] ?> <?= (int) $trip['days'] === 1 ? 'day' : 'days' ?><?php
        if ($trip['start_date']): ?>, <?= e(date('j M', strtotime($trip['start_date']))) ?>–<?= e(date('j M Y', strtotime($trip['start_date'] . ' +' . ((int) $trip['days'] - 1) . ' days'))) ?><?php endif; ?>
        · <?= count($items) ?> <?= count($items) === 1 ? 'place' : 'places' ?></p>
    <div class="trip-budget">
        <p class="trip-budget__label">Estimated spending at these places</p>
        <p class="trip-budget__amount"><?= $lo === $hi ? e(money($lo, $currency)) : e(money_number($lo, $currency) . '–' . money($hi, $currency)) ?></p>
        <p class="small muted">Entry tickets, meals and one night per hotel listed, converted to <?= e($currency) ?>.
            Flights, transport and shopping aren't included.<?= $unknown ? ' ' . $unknown . ' ' . ($unknown === 1 ? 'place has' : 'places have') . ' no price yet.' : '' ?></p>
    </div>
</header>

<div class="layout-split">
    <div class="layout-split__main">
        <?php if ($items): ?>
            <?= map_block('map-trip', $mapPoints, 'Map of this trip', 12, true) ?>
        <?php endif; ?>

        <ol class="trip-days">
            <?php foreach ($byDay as $day => $dayItems): [$dlo, $dhi] = trip_budget($dayItems, $currency); ?>
                <li class="trip-day">
                    <div class="trip-day__head">
                        <h2 class="trip-day__title">Day <?= $day ?><?php if ($d = $dayDate($day)): ?> <span class="muted">· <?= e($d) ?></span><?php endif; ?></h2>
                        <?php if ($dayItems): ?><span class="trip-day__cost"><?= $dlo === $dhi ? e(money($dlo, $currency)) : e(money_number($dlo, $currency) . '–' . money($dhi, $currency)) ?></span><?php endif; ?>
                    </div>
                    <?php if (!$dayItems): ?>
                        <p class="muted small">Nothing planned yet.</p>
                    <?php else: ?>
                        <ol class="trip-stops">
                            <?php foreach ($dayItems as $i => $p): $ph = $thumbs[(int) $p['id']] ?? null; ?>
                                <li class="trip-stop">
                                    <?php if ($ph): ?><a class="trip-stop__photo" href="<?= e(place_url((int) $p['id'])) ?>" tabindex="-1" aria-hidden="true"><?= photo_img($ph, 'thumb', '') ?></a><?php endif; ?>
                                    <div class="trip-stop__body">
                                        <h3 class="card__title"><a href="<?= e(place_url((int) $p['id'])) ?>"><?= e($p['name_en']) ?></a></h3>
                                        <p class="card__meta"><?= flag_img($p['iso2'], 16) ?> <?= e($p['city_name']) ?> · <?= e($p['category_name']) ?><?php
                                            if ($p['duration_hours']): ?> · about <?= e(rtrim(rtrim((string) $p['duration_hours'], '0'), '.')) ?> h<?php endif; ?></p>
                                        <div class="trip-stop__actions">
                                            <?php if ($i > 0): ?>
                                                <form method="post" class="inline-form"><?= $hidden(['action' => 'up', 'item_id' => $p['item_id']]) ?><button class="link-button small" aria-label="Move <?= e($p['name_en']) ?> earlier">↑ Earlier</button></form>
                                            <?php endif; ?>
                                            <?php if ($i < count($dayItems) - 1): ?>
                                                <form method="post" class="inline-form"><?= $hidden(['action' => 'down', 'item_id' => $p['item_id']]) ?><button class="link-button small" aria-label="Move <?= e($p['name_en']) ?> later">↓ Later</button></form>
                                            <?php endif; ?>
                                            <?php if ((int) $trip['days'] > 1): ?>
                                                <form method="post" class="inline-form trip-stop__move">
                                                    <?= $hidden(['action' => 'move', 'item_id' => $p['item_id']]) ?>
                                                    <label class="visually-hidden" for="move-<?= (int) $p['item_id'] ?>">Move to day</label>
                                                    <select id="move-<?= (int) $p['item_id'] ?>" name="day" data-autosubmit-select>
                                                        <?php for ($d = 1; $d <= (int) $trip['days']; $d++): ?>
                                                            <option value="<?= $d ?>"<?= $d === $day ? ' selected' : '' ?>>Day <?= $d ?></option>
                                                        <?php endfor; ?>
                                                    </select>
                                                    <button class="link-button small">Move</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="post" class="inline-form"><?= $hidden(['action' => 'remove', 'item_id' => $p['item_id']]) ?><button class="link-button link-button--danger small">Remove</button></form>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>

    <aside class="layout-split__side">
        <section class="panel" aria-labelledby="add-title">
            <h2 id="add-title" class="panel__title">Add saved places</h2>
            <?php if (!$saved): ?>
                <p class="small">All your saved places are in this trip. Save more with the ♡ Save button on any place,
                    or <a href="<?= e(url('random.php')) ?>">roll the dice</a> for ideas.</p>
            <?php else: ?>
                <ul class="add-list">
                    <?php foreach ($saved as $s): ?>
                        <li>
                            <span><?= flag_img($s['iso2'], 16) ?> <a href="<?= e(place_url((int) $s['id'])) ?>"><?= e($s['name_en']) ?></a>
                                <span class="muted small"><?= e($s['city_name']) ?></span></span>
                            <form method="post" class="add-list__form">
                                <?= $hidden(['action' => 'add', 'place_id' => $s['id']]) ?>
                                <label class="visually-hidden" for="add-<?= (int) $s['id'] ?>">Day for <?= e($s['name_en']) ?></label>
                                <select id="add-<?= (int) $s['id'] ?>" name="day">
                                    <?php for ($d = 1; $d <= (int) $trip['days']; $d++): ?><option value="<?= $d ?>">Day <?= $d ?></option><?php endfor; ?>
                                </select>
                                <button class="button button--small">Add</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="settings-title">
            <h2 id="settings-title" class="panel__title">Trip settings</h2>
            <form method="post">
                <?= $hidden(['action' => 'settings']) ?>
                <div class="field">
                    <label for="t-title">Name</label>
                    <input type="text" id="t-title" name="title" maxlength="120" required value="<?= e($trip['title']) ?>">
                </div>
                <div class="field field--short">
                    <label for="t-days">Days</label>
                    <input type="number" id="t-days" name="days" min="1" max="<?= RJ_TRIP_MAX_DAYS ?>" value="<?= (int) $trip['days'] ?>">
                </div>
                <p class="field__hint">If you remove days, their places move to the last day.</p>
                <button class="button button--small button--quiet">Save</button>
            </form>
        </section>
    </aside>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
