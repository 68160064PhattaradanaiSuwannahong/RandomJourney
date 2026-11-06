<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$city = $id ? find_city($id) : null;
if ($city === null) {
    not_found('city');
}

$stmt = db()->prepare(
    "SELECT p.id, p.name_en, p.name_th, p.summary, p.lat, p.lng, p.price_level, p.price_min, p.price_max,
            p.difficulty, p.avg_rating, p.review_count, cat.slug AS category_slug, cat.name_en AS category_name
     FROM rj_places p JOIN rj_categories cat ON cat.id = p.category_id
     WHERE p.city_id = ? AND p.status = 'published'
     ORDER BY cat.sort_order, p.name_en"
);
$stmt->execute([$city['id']]);
$places = $stmt->fetchAll();

// Group by category and work out distance from the city centre
$groups = [];
$mapPoints = [['lat' => (float) $city['lat'], 'lng' => (float) $city['lng'], 'name' => $city['name_en'] . ' centre', 'kind' => 'city']];
foreach ($places as $p) {
    $p['km'] = distance_km((float) $city['lat'], (float) $city['lng'], (float) $p['lat'], (float) $p['lng']);
    $groups[$p['category_name']][] = $p;
    $mapPoints[] = [
        'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'], 'name' => $p['name_en'],
        'note' => $p['category_name'] . ', ' . format_distance($p['km']) . ' from centre',
        'url' => place_url((int) $p['id']), 'kind' => $p['category_slug'],
    ];
}

$wx = weather((float) $city['lat'], (float) $city['lng']);

$cover = cover_photo('city', (int) $city['id']);
$thumbs = first_photos(array_column($places, 'id'));
$pageTitle = $city['name_en'] . ', ' . $city['country_name'];
$useMap = true;
require __DIR__ . '/includes/header.php';
?>
<?= breadcrumb([['Destinations', url()], [$city['country_name'], country_url($city['iso2'])], [$city['name_en'], null]]) ?>

<header class="place-hero<?= $cover ? ' place-hero--cover' : '' ?>">
    <?php if ($cover): ?>
        <div class="place-hero__cover"><?= photo_img($cover, 'large', '', '', false) ?></div>
        <p class="place-hero__credit"><?= photo_credit($cover) ?></p>
    <?php endif; ?>
    <h1 class="place-hero__title"><?= e($city['name_en']) ?></h1>
    <?php if ($city['summary']): ?><p class="place-hero__lead"><?= e($city['summary']) ?></p><?php endif; ?>
    <?php if ($city['best_season']): ?><p class="muted">Best time to visit: <?= e($city['best_season']) ?></p><?php endif; ?>
</header>

<div class="layout-split">
    <div class="layout-split__main">
        <?= map_block('map-city', $mapPoints, 'Map of places in ' . $city['name_en'], 12) ?>
        <p class="map-legend small">
            <span class="dot dot--attraction"></span>Sights
            <span class="dot dot--trail"></span>Trails
            <span class="dot dot--restaurant"></span>Food and drink
            <span class="dot dot--bar"></span>Bars
            <span class="dot dot--market"></span>Shopping
            <span class="dot dot--hotel"></span>Hotels
        </p>

        <?php if (!$places): ?>
            <p class="empty">No places added for <?= e($city['name_en']) ?> yet.</p>
        <?php endif; ?>

        <?php foreach ($groups as $category => $items): ?>
            <section class="section" aria-labelledby="cat-<?= e(md5($category)) ?>">
                <h2 class="section-title" id="cat-<?= e(md5($category)) ?>"><?= e($category) ?></h2>
                <ul class="card-list">
                    <?php foreach ($items as $p): ?>
                        <?php $ph = $thumbs[(int) $p['id']] ?? null; ?>
                        <li class="card<?= $ph ? ' card--photo' : '' ?>">
                            <?php if ($ph): ?><a class="card__photo" href="<?= e(place_url((int) $p['id'])) ?>" tabindex="-1" aria-hidden="true"><?= photo_img($ph, 'thumb', '') ?></a><div class="card__body"><?php endif; ?>
                            <h3 class="card__title"><a href="<?= e(place_url((int) $p['id'])) ?>"><?= e($p['name_en']) ?></a></h3>
                            <p class="card__text"><?= e($p['summary'] ?? '') ?></p>
                            <p class="card__meta">
                                <?= e(RJ_PRICE_WORDS[(int) $p['price_level']] ?? '') ?>,
                                <?= e(format_distance($p['km'])) ?> from centre
                                <?php if ($p['difficulty']): ?>, <?= e(RJ_DIFFICULTY_WORDS[(int) $p['difficulty']]) ?> trail<?php endif; ?>
                                <?php if ((int) $p['review_count'] > 0): ?>, ★ <?= number_format((float) $p['avg_rating'], 1) ?> (<?= (int) $p['review_count'] ?>)<?php endif; ?>
                            </p>
                            <?php if ($ph): ?></div><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>

        <?php if ($city['history']): ?>
            <section class="section prose" aria-labelledby="history-title">
                <h2 id="history-title" class="section-title">History</h2>
                <?= paragraphs($city['history']) ?>
            </section>
        <?php endif; ?>
    </div>

    <aside class="layout-split__side">
        <?php require __DIR__ . '/includes/weather_panel.php'; ?>
    </aside>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
