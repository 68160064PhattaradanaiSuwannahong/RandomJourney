<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$count = static fn (string $sql): int => (int) db()->query($sql)->fetchColumn();

$stats = [
    ['Destinations', $count('SELECT COUNT(*) FROM rj_countries WHERE is_destination = 1'), 'admin/list.php?e=country'],
    ['Cities',       $count('SELECT COUNT(*) FROM rj_cities'), 'admin/list.php?e=city'],
    ['Places',       $count('SELECT COUNT(*) FROM rj_places'), 'admin/list.php?e=place'],
    ['Users',        $count('SELECT COUNT(*) FROM rj_users'), 'admin/users.php'],
    ['Reviews',      $count('SELECT COUNT(*) FROM rj_reviews'), 'admin/reports.php'],
    ['Open reports', open_report_count(), 'admin/reports.php'],
];

// Content gaps: what still needs data before the site feels complete
$gaps = [];

foreach (db()->query(
    'SELECT c.id, c.name_en,
            (SELECT COUNT(*) FROM rj_cities ci WHERE ci.country_id = c.id) AS cities,
            (SELECT COUNT(DISTINCT r.risk_type) FROM rj_risk_scores r WHERE r.country_id = c.id AND r.city_id IS NULL) AS risks,
            (c.summary IS NULL OR c.summary = \'\') AS no_summary,
            c.cost_index IS NULL AS no_cost
     FROM rj_countries c WHERE c.is_destination = 1 ORDER BY c.name_en'
) as $c) {
    $link = 'admin/edit.php?e=country&id=' . $c['id'];
    if ((int) $c['cities'] < 2) {
        $gaps[] = [$c['name_en'], 'has ' . $c['cities'] . ' of 2 cities', 'admin/edit.php?e=city&country_id=' . $c['id']];
    }
    if ((int) $c['risks'] < 6) {
        $gaps[] = [$c['name_en'], 'has ' . $c['risks'] . ' of 6 risk scores', 'admin/list.php?e=risk&country_id=' . $c['id']];
    }
    if ($c['no_summary'] || $c['no_cost']) {
        $gaps[] = [$c['name_en'], 'is missing its summary or cost level', $link];
    }
}

foreach (db()->query(
    'SELECT ci.id, ci.name_en, COUNT(p.id) AS places
     FROM rj_cities ci LEFT JOIN rj_places p ON p.city_id = ci.id
     GROUP BY ci.id, ci.name_en HAVING places < 8 ORDER BY places, ci.name_en'
) as $ci) {
    $gaps[] = [$ci['name_en'], 'has ' . $ci['places'] . ' of 8 places', 'admin/list.php?e=place&city_id=' . $ci['id']];
}

$noTags = $count('SELECT COUNT(*) FROM rj_places p WHERE NOT EXISTS (SELECT 1 FROM rj_place_tags t WHERE t.place_id = p.id)');
if ($noTags > 0) {
    $gaps[] = [$noTags . ($noTags === 1 ? ' place' : ' places'), 'without any tags (they won\'t match theme filters)', 'admin/list.php?e=place'];
}

// Can the server reach the external APIs? (results are cached, so this is cheap)
$fx = fx_rates();
$firstCity = db()->query('SELECT name_en, lat, lng FROM rj_cities ORDER BY id LIMIT 1')->fetch();
$wxTest = $firstCity ? weather((float) $firstCity['lat'], (float) $firstCity['lng']) : null;

$pageTitle = 'Admin';
$bodyClass = 'page-admin';
require dirname(__DIR__) . '/includes/header.php';
echo admin_nav('dashboard');
?>
<h1 class="page-title">Overview</h1>

<dl class="stats">
    <?php foreach ($stats as [$label, $value, $href]): ?>
        <div class="stat">
            <dt><?= e($label) ?></dt>
            <dd><?= $href ? '<a href="' . e(url($href)) . '">' . $value . '</a>' : $value ?></dd>
        </div>
    <?php endforeach; ?>
</dl>

<section class="section" aria-labelledby="api-title">
    <h2 id="api-title" class="section-title">External data</h2>
    <ul class="gap-list">
        <li><span class="gap-list__row"><strong>Exchange rates</strong>
            <?= $fx ? 'working, from ' . e($fx['source']) . ', fetched ' . e($fx['_fetched_at']) . (!empty($fx['_stale']) ? ' (stale: last refresh failed)' : '')
                    : '<span class="badge badge--warn">unreachable</span> prices show in local currency only' ?></span></li>
        <li><span class="gap-list__row"><strong>Weather</strong>
            <?= $wxTest ? 'working (tested with ' . e($firstCity['name_en']) . ')' : '<span class="badge badge--warn">unreachable</span> weather panels are hidden' ?></span></li>
    </ul>
</section>

<section aria-labelledby="gaps-title">
    <h2 id="gaps-title" class="section-title">Still to fill in</h2>
    <?php if (!$gaps): ?>
        <p class="empty">Every destination has its cities, places and risk scores.</p>
    <?php else: ?>
        <ul class="gap-list">
            <?php foreach ($gaps as [$what, $issue, $href]): ?>
                <li><a href="<?= e(url($href)) ?>"><strong><?= e($what) ?></strong> <?= e($issue) ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
