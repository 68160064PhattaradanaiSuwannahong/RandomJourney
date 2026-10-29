<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$destinations = db()->query(
    "SELECT c.iso2, c.name_en, c.name_th, c.currency_code, c.cost_index, r.overall_risk,
            (SELECT COUNT(*) FROM rj_cities ci WHERE ci.country_id = c.id) AS city_count,
            (SELECT COUNT(*) FROM rj_places p JOIN rj_cities ci ON ci.id = p.city_id
             WHERE ci.country_id = c.id AND p.status = 'published') AS place_count
     FROM rj_countries c
     LEFT JOIN rj_v_country_risk r ON r.country_id = c.id
     WHERE c.is_destination = 1
     ORDER BY c.name_en"
)->fetchAll();

$user = current_user();
$pageTitle = '';
require __DIR__ . '/includes/header.php';
?>
<section class="hero">
    <h1 class="hero__title">Where to next?</h1>
    <p class="hero__lead">
        Tell us your budget, how much risk you're comfortable with and whether you want mountains,
        sea or old cities. We'll suggest the best match, or spin for somewhere you'd never have picked.
    </p>
    <p class="hero__actions">
        <a class="button" href="<?= e(url('recommend.php')) ?>">Find my best match</a>
        <a class="button button--quiet" href="<?= e(url('random.php')) ?>">Spin for a random trip</a>
        <?php if (!$user): ?>
            <a class="link" href="<?= e(url('register.php')) ?>">Create a free account</a>
        <?php endif; ?>
    </p>
</section>

<section aria-labelledby="destinations-title">
    <h2 id="destinations-title" class="section-title">Destinations so far</h2>
    <?php if (!$destinations): ?>
        <p class="empty">No destinations yet. Add one from the admin panel.</p>
    <?php else: ?>
        <ul class="destinations">
            <?php foreach ($destinations as $d): ?>
                <li class="destination">
                    <span class="destination__flag"><?= flag_img($d['iso2']) ?></span>
                    <a class="destination__name" href="<?= e(country_url($d['iso2'])) ?>"><?= e($d['name_en']) ?></a>
                    <span class="destination__local" lang="th"><?= e($d['name_th'] ?? '') ?></span>
                    <span class="destination__meta">
                        <?= (int) $d['city_count'] === 1 ? '1 city' : e($d['city_count'] . ' cities') ?>,
                        <?= (int) $d['place_count'] ?> places,
                        <?= e(strtolower(RJ_COST_WORDS[(int) $d['cost_index']] ?? 'cost unknown')) ?>,
                        <?= $d['overall_risk'] !== null ? 'risk ' . number_format((float) $d['overall_risk'], 1) . '/5' : 'risk not rated' ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
