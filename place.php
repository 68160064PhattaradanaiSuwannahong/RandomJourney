<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$place = $id ? find_place($id) : null;
if ($place === null) {
    http_response_code(404);
    $pageTitle = 'Place not found';
    require __DIR__ . '/includes/header.php';
    echo '<h1 class="page-title">We couldn\'t find that place</h1><p><a href="' . e(url()) . '">See all destinations</a></p>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$currency = (string) $place['currency_code'];
$tags = place_tags((int) $place['id']);
$kmFromCentre = distance_km((float) $place['city_lat'], (float) $place['city_lng'], (float) $place['lat'], (float) $place['lng']);

$stmt = db()->prepare('SELECT risk_type, title, tip FROM rj_place_safety WHERE place_id = ? ORDER BY id');
$stmt->execute([$place['id']]);
$safetyTips = $stmt->fetchAll();

// Nearby places in the same city, closest first
$stmt = db()->prepare(
    "SELECT p.id, p.name_en, p.lat, p.lng, cat.slug AS category_slug, cat.name_en AS category_name
     FROM rj_places p JOIN rj_categories cat ON cat.id = p.category_id
     WHERE p.city_id = ? AND p.id <> ? AND p.status = 'published'"
);
$stmt->execute([$place['city_id'], $place['id']]);
$nearby = array_map(function ($n) use ($place) {
    $n['km'] = distance_km((float) $place['lat'], (float) $place['lng'], (float) $n['lat'], (float) $n['lng']);
    return $n;
}, $stmt->fetchAll());
usort($nearby, fn ($a, $b) => $a['km'] <=> $b['km']);
$nearby = array_slice($nearby, 0, 5);

$mapPoints = [['lat' => (float) $place['lat'], 'lng' => (float) $place['lng'], 'name' => $place['name_en'], 'kind' => 'here']];
foreach ($nearby as $n) {
    $mapPoints[] = ['lat' => (float) $n['lat'], 'lng' => (float) $n['lng'], 'name' => $n['name_en'],
        'note' => format_distance($n['km']) . ' away', 'url' => place_url((int) $n['id']), 'kind' => $n['category_slug']];
}

$wx = weather((float) $place['city_lat'], (float) $place['city_lng']);
$city = ['name_en' => $place['city_name']];

$priceText = price_range(
    $place['price_min'] !== null ? (float) $place['price_min'] : null,
    $place['price_max'] !== null ? (float) $place['price_max'] : null,
    $currency
);

// Reviews
$sort = ($_GET['sort'] ?? '') === 'newest' ? 'newest' : 'helpful';
$reviews = place_reviews((int) $place['id'], $sort);
$mine = my_review((int) $place['id']);
$reviewForm = ['rating' => $mine['rating'] ?? '', 'title' => $mine['title'] ?? '', 'body' => $mine['body'] ?? '',
               'visited_at' => !empty($mine['visited_at']) ? substr($mine['visited_at'], 0, 7) : ''];
$reviewErrors = [];
if (($_SESSION['review_form']['place_id'] ?? null) === (int) $place['id']) {
    $reviewForm = $_SESSION['review_form']['form'];
    $reviewErrors = $_SESSION['review_form']['errors'];
}
unset($_SESSION['review_form']);
$viewer = current_user();
$saved = is_favorite((int) $place['id']);

$directions = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($place['lat'] . ',' . $place['lng']);

$pageTitle = $place['name_en'] . ', ' . $place['city_name'];
$useMap = true;
require __DIR__ . '/includes/header.php';
?>
<p class="breadcrumb">
    <a href="<?= e(url()) ?>">Destinations</a> /
    <a href="<?= e(country_url($place['iso2'])) ?>"><?= e($place['country_name']) ?></a> /
    <a href="<?= e(city_url((int) $place['city_id'])) ?>"><?= e($place['city_name']) ?></a>
</p>

<header class="place-hero">
    <p class="place-hero__category"><?= e($place['category_name']) ?></p>
    <h1 class="place-hero__title place-hero__title--place"><?= e($place['name_en']) ?></h1>
    <?php if ($place['name_th']): ?><p class="place-hero__local" lang="th"><?= e($place['name_th']) ?></p><?php endif; ?>
    <?php if ($place['summary']): ?><p class="place-hero__lead"><?= e($place['summary']) ?></p><?php endif; ?>
    <div class="place-hero__actions">
        <?php if ((int) $place['review_count'] > 0): ?>
            <a class="rating-link" href="#reviews"><span class="stars" aria-hidden="true"><?= stars((float) $place['avg_rating']) ?></span>
                <?= number_format((float) $place['avg_rating'], 1) ?> (<?= (int) $place['review_count'] ?> <?= (int) $place['review_count'] === 1 ? 'review' : 'reviews' ?>)</a>
        <?php endif; ?>
        <?php if ($viewer): ?>
            <form method="post" action="<?= e(url('favorite.php')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="place_id" value="<?= (int) $place['id'] ?>">
                <button type="submit" class="button button--small <?= $saved ? '' : 'button--quiet' ?>" aria-pressed="<?= $saved ? 'true' : 'false' ?>">
                    <?= $saved ? '♥ Saved' : '♡ Save' ?></button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($tags): ?>
        <ul class="tag-list" aria-label="Tags">
            <?php foreach ($tags as $t): ?><li><?= e($t['name_en']) ?></li><?php endforeach; ?>
        </ul>
    <?php endif; ?>
</header>

<div class="layout-split">
    <div class="layout-split__main">
        <section class="section prose" aria-labelledby="about-title">
            <h2 id="about-title" class="visually-hidden">About</h2>
            <?= paragraphs($place['description']) ?>
            <?php if ($place['history']): ?><h3>History</h3><?= paragraphs($place['history']) ?><?php endif; ?>
            <?php if ($place['culture_beliefs']): ?><h3>Culture and beliefs</h3><?= paragraphs($place['culture_beliefs']) ?><?php endif; ?>
        </section>

        <section class="section" aria-labelledby="map-title">
            <h2 id="map-title" class="section-title">Getting there</h2>
            <?= map_block('map-place', $mapPoints, 'Map of ' . $place['name_en'] . ' and nearby places', 15) ?>
            <p><strong><?= e(format_distance($kmFromCentre)) ?></strong> from <?= e($place['city_name']) ?> centre,
                <?= e(travel_hint($kmFromCentre)) ?> (straight-line estimate).
                <a href="<?= e($directions) ?>" rel="noopener" target="_blank">Get directions in Google Maps</a></p>
            <?php if ($place['how_to_get_there']): ?><?= paragraphs($place['how_to_get_there']) ?><?php endif; ?>
            <?php if ($place['address']): ?><p class="muted">Address: <?= e($place['address']) ?></p><?php endif; ?>
        </section>

        <?php if ($safetyTips): ?>
            <section class="section" aria-labelledby="safety-title">
                <h2 id="safety-title" class="section-title">Stay safe here</h2>
                <ul class="safety-list">
                    <?php foreach ($safetyTips as $tip): ?>
                        <li><strong><?= e($tip['title']) ?></strong><?= paragraphs($tip['tip']) ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="small"><a href="<?= e(country_url($place['iso2'])) ?>#safety-title">Safety advice for all of <?= e($place['country_name']) ?></a></p>
            </section>
        <?php endif; ?>


        <section class="section" id="reviews" aria-labelledby="reviews-title">
            <div class="section-head">
                <h2 id="reviews-title" class="section-title">Reviews</h2>
                <?php if (count($reviews) > 1): ?>
                    <p class="sort-links small">Sort:
                        <a href="<?= e(place_url((int) $place['id']) . '&sort=helpful#reviews') ?>"<?= $sort === 'helpful' ? ' aria-current="true"' : '' ?>>Most helpful</a>
                        <a href="<?= e(place_url((int) $place['id']) . '&sort=newest#reviews') ?>"<?= $sort === 'newest' ? ' aria-current="true"' : '' ?>>Newest</a></p>
                <?php endif; ?>
            </div>

            <?php if (!$reviews): ?>
                <p class="muted">No reviews yet. Been here? Be the first to share what it was like.</p>
            <?php endif; ?>

            <ol class="review-list">
                <?php foreach ($reviews as $r): $own = $viewer && (int) $r['user_id'] === (int) $viewer['id']; ?>
                    <li class="review" id="review-<?= (int) $r['id'] ?>">
                        <p class="review__head">
                            <span class="stars" aria-label="<?= (int) $r['rating'] ?> out of 5 stars"><?= stars((float) $r['rating']) ?></span>
                            <?php if ($r['title']): ?><strong class="review__title"><?= e($r['title']) ?></strong><?php endif; ?>
                        </p>
                        <?= paragraphs($r['body']) ?>
                        <p class="review__meta small muted">
                            <?= e($r['author']) ?><?php if ($r['author_iso']): ?> <?= flag_img($r['author_iso'], 16) ?> <?= e($r['author_country']) ?><?php endif; ?>,
                            <?= $r['visited_at'] ? 'visited ' . e(date('M Y', strtotime($r['visited_at']))) : 'posted ' . e(date('j M Y', strtotime($r['created_at']))) ?>
                            <?php if ((int) $r['helpful_count'] > 0): ?>, <?= (int) $r['helpful_count'] ?> found this helpful<?php endif; ?>
                            <?php if ($own): ?><span class="badge">Your review</span><?php endif; ?>
                        </p>
                        <?php if ($viewer && !$own): ?>
                            <div class="review__actions">
                                <form method="post" action="<?= e(url('review.php')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="place_id" value="<?= (int) $place['id'] ?>">
                                    <input type="hidden" name="review_id" value="<?= (int) $r['id'] ?>">
                                    <input type="hidden" name="action" value="helpful">
                                    <button type="submit" class="link-button" aria-pressed="<?= (int) $r['i_found_helpful'] ? 'true' : 'false' ?>">
                                        <?= (int) $r['i_found_helpful'] ? '✓ Helpful' : 'Helpful' ?></button>
                                </form>
                                <?php if ((int) $r['i_reported']): ?>
                                    <span class="muted small">Reported</span>
                                <?php else: ?>
                                    <details class="report">
                                        <summary class="link-button">Report</summary>
                                        <form method="post" action="<?= e(url('review.php')) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="place_id" value="<?= (int) $place['id'] ?>">
                                            <input type="hidden" name="review_id" value="<?= (int) $r['id'] ?>">
                                            <input type="hidden" name="action" value="report">
                                            <label for="reason-<?= (int) $r['id'] ?>">Why are you reporting it?</label>
                                            <select id="reason-<?= (int) $r['id'] ?>" name="reason" required>
                                                <option value="">Choose a reason</option>
                                                <?php foreach (RJ_REPORT_REASONS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
                                            </select>
                                            <label for="details-<?= (int) $r['id'] ?>">Details <span class="optional">(optional)</span></label>
                                            <input id="details-<?= (int) $r['id'] ?>" type="text" name="details" maxlength="500">
                                            <button type="submit" class="button button--small button--danger">Send report</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                                <?php if ($viewer['role'] === 'admin'): ?>
                                    <form method="post" action="<?= e(url('review.php')) ?>" class="inline-form" data-confirm="Hide this review from everyone?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="place_id" value="<?= (int) $place['id'] ?>">
                                        <input type="hidden" name="review_id" value="<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="action" value="hide">
                                        <button type="submit" class="link-button link-button--danger">Hide (admin)</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>

            <div class="panel review-form" id="write-review">
                <?php if (!$viewer): ?>
                    <p><a href="<?= e(url('login.php?next=' . rawurlencode(place_url((int) $place['id']) . '#write-review'))) ?>">Log in</a> to write a review.</p>
                <?php else: ?>
                    <h3 class="panel__title"><?= $mine ? 'Edit your review' : 'Write a review' ?></h3>
                    <?php if ($mine && $mine['status'] === 'hidden'): ?>
                        <p class="notice notice--info">An admin has hidden your review, so other people can't see it.</p>
                    <?php endif; ?>
                    <?= form_errors_summary($reviewErrors) ?>
                    <form method="post" action="<?= e(url('review.php')) ?>" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="place_id" value="<?= (int) $place['id'] ?>">
                        <input type="hidden" name="action" value="save">
                        <fieldset class="field star-input"<?= field_aria($reviewErrors, 'rating') ?>>
                            <legend>Your rating</legend>
                            <div class="star-input__options">
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                    <input type="radio" id="star<?= $i ?>" name="rating" value="<?= $i ?>"<?= (string) $reviewForm['rating'] === (string) $i ? ' checked' : '' ?>>
                                    <label for="star<?= $i ?>" title="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>">★<span class="visually-hidden"> <?= $i ?> star<?= $i > 1 ? 's' : '' ?></span></label>
                                <?php endfor; ?>
                            </div>
                            <?= field_error($reviewErrors, 'rating') ?>
                        </fieldset>
                        <div class="field">
                            <label for="title">Headline <span class="optional">(optional)</span></label>
                            <input id="title" name="title" type="text" maxlength="150" value="<?= e($reviewForm['title']) ?>"<?= field_aria($reviewErrors, 'title') ?>>
                            <?= field_error($reviewErrors, 'title') ?>
                        </div>
                        <div class="field">
                            <label for="body">Your review</label>
                            <textarea id="body" name="body" rows="5" maxlength="<?= RJ_REVIEW_MAX ?>"<?= field_aria($reviewErrors, 'body') ?>><?= e($reviewForm['body']) ?></textarea>
                            <p class="field__hint">What was it like? Tips on timing, prices, crowds or safety help the most.</p>
                            <?= field_error($reviewErrors, 'body') ?>
                        </div>
                        <div class="field field--short">
                            <label for="visited_at">When you visited <span class="optional">(optional)</span></label>
                            <input id="visited_at" name="visited_at" type="month" max="<?= date('Y-m') ?>" value="<?= e($reviewForm['visited_at']) ?>"<?= field_aria($reviewErrors, 'visited_at') ?>>
                            <?= field_error($reviewErrors, 'visited_at') ?>
                        </div>
                        <button type="submit" class="button"><?= $mine ? 'Update review' : 'Publish review' ?></button>
                    </form>
                    <?php if ($mine): ?>
                        <form method="post" action="<?= e(url('review.php')) ?>" class="review-form__delete" data-confirm="Delete your review permanently?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="place_id" value="<?= (int) $place['id'] ?>">
                            <input type="hidden" name="action" value="delete">
                            <button type="submit" class="link-button link-button--danger">Delete my review</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($nearby): ?>
            <section class="section" aria-labelledby="nearby-title">
                <h2 id="nearby-title" class="section-title">Nearby</h2>
                <ul class="nearby-list">
                    <?php foreach ($nearby as $n): ?>
                        <li><a href="<?= e(place_url((int) $n['id'])) ?>"><?= e($n['name_en']) ?></a>
                            <span class="muted"><?= e($n['category_name']) ?>, <?= e(format_distance($n['km'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>

    <aside class="layout-split__side">
        <section class="panel" aria-labelledby="info-title">
            <h2 id="info-title" class="panel__title">Plan your visit</h2>
            <dl class="info-list">
                <div><dt>Price</dt><dd><?= e(RJ_PRICE_WORDS[(int) $place['price_level']] ?? '') ?><?= $priceText ? '<br><span class="muted">' . e($priceText) . '</span>' : '' ?></dd></div>
                <?php if ($place['opening_hours']): ?><div><dt>Opening hours</dt><dd><?= e($place['opening_hours']) ?></dd></div><?php endif; ?>
                <?php if ($place['duration_hours']): ?><div><dt>Time needed</dt><dd>About <?= e(rtrim(rtrim((string) $place['duration_hours'], '0'), '.')) ?> hours</dd></div><?php endif; ?>
                <?php if ($place['difficulty']): ?><div><dt>Trail difficulty</dt><dd><?= level_meter((int) $place['difficulty'], RJ_DIFFICULTY_WORDS[(int) $place['difficulty']]) ?> <?= e(RJ_DIFFICULTY_WORDS[(int) $place['difficulty']]) ?></dd></div><?php endif; ?>
                <?php if ($place['dress_code']): ?><div><dt>Dress code</dt><dd><?= e($place['dress_code']) ?></dd></div><?php endif; ?>
                <?php if ((int) $place['permit_required'] || $place['permit_info']): ?>
                    <div><dt>Permits and booking</dt><dd><?= (int) $place['permit_required'] ? '<strong>Required.</strong> ' : '' ?><?= e($place['permit_info'] ?? '') ?></dd></div>
                <?php endif; ?>
                <?php if ($place['website_url']): ?><div><dt>Website</dt><dd><a href="<?= e($place['website_url']) ?>" rel="noopener" target="_blank">Official site</a></dd></div><?php endif; ?>
            </dl>
            <p class="small muted">Prices and hours change. Check before you go.</p>
        </section>

        <?php require __DIR__ . '/includes/weather_panel.php'; ?>
    </aside>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
