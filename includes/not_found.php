<?php
/**
 * "Lost in the archives": the 404 page. Call not_found('place') and stop.
 */
declare(strict_types=1);

if (!defined('RJ_BOOT')) { http_response_code(403); exit; }

function not_found(string $what = 'page'): never
{
    http_response_code(404);
    $pageTitle = 'Lost in the archives';
    $bodyClass = 'page-lost';
    // Three random places to wander to instead
    $picks = db()->query(
        "SELECT p.id, p.name_en, ci.name_en AS city_name, co.iso2
         FROM rj_places p JOIN rj_cities ci ON ci.id = p.city_id JOIN rj_countries co ON co.id = ci.country_id
         WHERE p.status = 'published' AND co.is_destination = 1 AND EXISTS (SELECT 1 FROM rj_photos ph WHERE ph.place_id = p.id)
         ORDER BY RAND() LIMIT 3"
    )->fetchAll();
    $thumbs = first_photos(array_column($picks, 'id'));
    require __DIR__ . '/header.php';
    ?>
    <section class="lost">
        <p class="lost__code" aria-hidden="true">404</p>
        <h1 class="page-title">This <?= e($what) ?> is lost in the archives</h1>
        <p class="lost__lead">The librarian searched every shelf, but the <?= e($what) ?> you asked for isn't in our collection.
            Perhaps the dice can find you somewhere better.</p>
        <p class="lost__actions">
            <a class="button button--spin" href="<?= e(url('random.php')) ?>">Roll the dice</a>
            <a class="button button--quiet" href="<?= e(url()) ?>">Browse destinations</a>
        </p>
        <?php if ($picks): ?>
            <h2 class="section-title">Or wander to one of these</h2>
            <ul class="card-list card-list--three">
                <?php foreach ($picks as $p): $ph = $thumbs[(int) $p['id']] ?? null; ?>
                    <li class="card<?= $ph ? ' card--photo' : '' ?>">
                        <?php if ($ph): ?><a class="card__photo" href="<?= e(place_url((int) $p['id'])) ?>" tabindex="-1" aria-hidden="true"><?= photo_img($ph, 'thumb', '') ?></a><div class="card__body"><?php endif; ?>
                        <h3 class="card__title"><a href="<?= e(place_url((int) $p['id'])) ?>"><?= e($p['name_en']) ?></a></h3>
                        <p class="card__meta"><?= flag_img($p['iso2'], 16) ?> <?= e($p['city_name']) ?></p>
                        <?php if ($ph): ?></div><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}
