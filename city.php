<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$city = $id ? find_city($id) : null;
if ($city === null) {
    http_response_code(404);
    $pageTitle = 'City not found';
    require __DIR__ . '/includes/header.php';
    echo '<h1 class="page-title">We couldn\'t find that city</h1><p><a href="' . e(url()) . '">See all destinations</a></p>';
    require __DIR__ . '/includes/footer.php';
    exit;
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

$pageTitle = $city['name_en'] . ', ' . $city['country_name'];
$useMap = true;
require __DIR__ . '/includes/header.php';
?>
<p class="breadcrumb">
    <a href="<?= e(url()) ?>">Destinations</a> /
    <a href="<?= e(country_url($city['iso2'])) ?>"><?= e($city['country_name']) ?></a>
</p>

<header class="place-hero">
    <h1 class="place-hero__title"><?= e($city['name_en']) ?></h1>
    <?php if ($city['name_th']): ?><p class="place-hero__local" lang="th"><?= e($city['name_th']) ?></p><?php endif; ?>
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
                        <li class="card">
                            <h3 class="card__title"><a href="<?= e(place_url((int) $p['id'])) ?>"><?= e($p['name_en']) ?></a></h3>
                            <?php if ($p['name_th']): ?><p class="card__local" lang="th"><?= e($p['name_th']) ?></p><?php endif; ?>
                            <p class="card__text"><?= e($p['summary'] ?? '') ?></p>
                            <p class="card__meta">
                                <?= e(RJ_PRICE_WORDS[(int) $p['price_level']] ?? '') ?>,
                                <?= e(format_distance($p['km'])) ?> from centre
                                <?php if ($p['difficulty']): ?>, <?= e(RJ_DIFFICULTY_WORDS[(int) $p['difficulty']]) ?> trail<?php endif; ?>
                                <?php if ((int) $p['review_count'] > 0): ?>, ★ <?= number_format((float) $p['avg_rating'], 1) ?> (<?= (int) $p['review_count'] ?>)<?php endif; ?>
                            </p>
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
