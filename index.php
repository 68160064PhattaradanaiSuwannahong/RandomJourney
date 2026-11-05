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
    <div>
    <p class="hero__kicker">A travel library for the undecided</p>
    <h1 class="hero__title">Where to next?</h1>
    <p class="hero__lead">
        Tell us your budget, how much risk you're comfortable with and whether you want mountains,
        sea or old cities. We'll suggest the best match, or spin for somewhere you'd never have picked.
    </p>
    <p class="hero__actions">
        <a class="button" href="<?= e(url('recommend.php')) ?>">Find my best match</a>
        <a class="button button--quiet" href="<?= e(url('random.php')) ?>">Roll the dice for a trip</a>
        <?php if (!$user): ?>
            <a class="link" href="<?= e(url('register.php')) ?>">Create a free account</a>
        <?php endif; ?>
    </p>
    </div>
    <div class="hero__emblem orrery" aria-hidden="true">
        <div class="orrery__glow"></div>
        <div class="orrery__gimbal">
            <span class="orrery__ring orrery__ring--a"></span>
            <span class="orrery__ring orrery__ring--b"></span>
            <span class="orrery__ring orrery__ring--c"></span>
            <span class="orrery__ring orrery__ring--d"></span>
        <svg class="orrery__compass" viewBox="-16 -16 232 232" focusable="false">
            <g class="dial">
                <circle class="ring" cx="100" cy="100" r="96"/>
                <circle class="ring ring--bold" cx="100" cy="100" r="88"/>
                <path class="ticks" d="<?php for ($a = 0; $a < 360; $a += 10) {
                    $r1 = $a % 30 === 0 ? 76 : 81;
                    printf('M%.1f %.1fL%.1f %.1f', 100 + 88 * sin(deg2rad($a)), 100 - 88 * cos(deg2rad($a)), 100 + $r1 * sin(deg2rad($a)), 100 - $r1 * cos(deg2rad($a)));
                } ?>"/>
            </g>
            <circle class="ring" cx="100" cy="100" r="62"/>
            <path class="star star--minor" d="M100 100 L132 68 L106 100 L132 132 L100 106 L68 132 L94 100 L68 68 L100 94Z"/>
            <path class="star" d="M100 20 L110 90 L180 100 L110 110 L100 180 L90 110 L20 100 L90 90Z"/>
            <text class="letters" x="100" y="-7">N</text><text class="letters" x="208" y="101">E</text>
            <text class="letters" x="100" y="209">S</text><text class="letters" x="-8" y="101">W</text>
        </svg>
        </div>
    </div>
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
                    <?= endonym_html($d['iso2'], 'destination__local') ?>
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
