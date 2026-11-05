<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$errors = [];
$form = ['title' => '', 'start_date' => '', 'days' => '3'];

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    if ($action === 'create') {
        $form = ['title' => post_str('title'), 'start_date' => post_str('start_date'), 'days' => post_str('days')];
        $days = filter_var($form['days'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => RJ_TRIP_MAX_DAYS]]);
        if ($form['title'] === '' || mb_strlen($form['title']) > 120) {
            $errors['title'] = 'Give your trip a name (up to 120 characters).';
        }
        if ($days === false) {
            $errors['days'] = 'Choose between 1 and ' . RJ_TRIP_MAX_DAYS . ' days.';
        }
        $date = null;
        if ($form['start_date'] !== '') {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $form['start_date']);
            if ($d === false || $d->format('Y-m-d') !== $form['start_date']) {
                $errors['start_date'] = 'Use a real date, or leave it empty.';
            } else {
                $date = $form['start_date'];
            }
        }
        if (count(user_trips((int) $user['id'])) >= 50) {
            $errors['title'] = 'You already have 50 trips. Delete an old one first.';
        }
        if (!$errors) {
            db()->prepare('INSERT INTO rj_trips (user_id, title, start_date, days) VALUES (?, ?, ?, ?)')
                ->execute([$user['id'], $form['title'], $date, $days]);
            $tripId = (int) db()->lastInsertId();
            // Start the trip with a place, if the form came from a place page
            $placeId = post_int('place_id');
            if ($placeId !== null && find_place($placeId)) {
                db()->prepare('INSERT IGNORE INTO rj_trip_items (trip_id, place_id, day_no, sort_order) VALUES (?, ?, 1, 0)')
                    ->execute([$tripId, $placeId]);
            }
            flash('success', 'Trip created. Add places from your saved list.');
            redirect('trip.php?id=' . $tripId);
        }
    } elseif ($action === 'delete') {
        $trip = find_trip((int) post_int('trip_id'), (int) $user['id']);
        if ($trip) {
            db()->prepare('DELETE FROM rj_trips WHERE id = ?')->execute([$trip['id']]);
            flash('info', 'Trip "' . $trip['title'] . '" deleted.');
        }
        redirect('trips.php');
    }
}

$trips = user_trips((int) $user['id']);
$pageTitle = 'Your trips';
require __DIR__ . '/includes/header.php';
?>
<header class="tool-head">
    <h1 class="page-title">Your trips</h1>
    <p class="tool-head__lead">Plan your days from the places you've saved. Each trip adds up an estimated budget in <?= e(display_currency()) ?>.</p>
</header>

<div class="layout-split">
    <div class="layout-split__main">
        <?php if (!$trips): ?>
            <div class="empty-state">
                <p class="empty-state__title">No trips yet</p>
                <p>Save a few places with ♡ Save, then create a trip here and arrange them by day.</p>
            </div>
        <?php else: ?>
            <ul class="card-list">
                <?php foreach ($trips as $t): ?>
                    <li class="card trip-card">
                        <h2 class="card__title"><a href="<?= e(url('trip.php?id=' . (int) $t['id'])) ?>"><?= e($t['title']) ?></a></h2>
                        <p class="card__meta"><?= (int) $t['days'] ?> <?= (int) $t['days'] === 1 ? 'day' : 'days' ?> ·
                            <?= (int) $t['items'] ?> <?= (int) $t['items'] === 1 ? 'place' : 'places' ?><?php
                            if ($t['start_date']): ?> · from <?= e(date('j M Y', strtotime($t['start_date']))) ?><?php endif; ?></p>
                        <form method="post" class="inline-form" data-confirm="Delete this trip? This can't be undone.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="trip_id" value="<?= (int) $t['id'] ?>">
                            <button type="submit" class="link-button link-button--danger small">Delete</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <aside class="layout-split__side">
        <section class="panel" aria-labelledby="new-trip-title">
            <h2 id="new-trip-title" class="panel__title">New trip</h2>
            <?= form_errors_summary($errors) ?>
            <form method="post" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="field">
                    <label for="title">Name</label>
                    <input type="text" id="title" name="title" maxlength="120" required value="<?= e($form['title']) ?>" placeholder="Spring in Kyoto"<?= field_aria($errors, 'title') ?>>
                    <?= field_error($errors, 'title') ?>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="start_date">Start date <span class="optional">(optional)</span></label>
                        <input type="date" id="start_date" name="start_date" value="<?= e($form['start_date']) ?>"<?= field_aria($errors, 'start_date') ?>>
                        <?= field_error($errors, 'start_date') ?>
                    </div>
                    <div class="field">
                        <label for="days">Days</label>
                        <input type="number" id="days" name="days" min="1" max="<?= RJ_TRIP_MAX_DAYS ?>" value="<?= e($form['days']) ?>"<?= field_aria($errors, 'days') ?>>
                        <?= field_error($errors, 'days') ?>
                    </div>
                </div>
                <button type="submit" class="button button--wide">Create trip</button>
            </form>
        </section>
    </aside>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
