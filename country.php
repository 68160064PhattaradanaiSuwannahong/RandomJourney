<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$country = find_country(is_string($_GET['c'] ?? null) ? $_GET['c'] : '');
if ($country === null) {
    http_response_code(404);
    $pageTitle = 'Destination not found';
    require __DIR__ . '/includes/header.php';
    echo '<h1 class="page-title">We don\'t cover that country yet</h1><p><a href="' . e(url()) . '">See all destinations</a></p>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$stmt = db()->prepare(
    "SELECT ci.id, ci.name_en, ci.name_th, ci.lat, ci.lng, ci.summary, ci.environment,
            COUNT(p.id) AS places
     FROM rj_cities ci LEFT JOIN rj_places p ON p.city_id = ci.id AND p.status = 'published'
     WHERE ci.country_id = ? GROUP BY ci.id ORDER BY ci.name_en"
);
$stmt->execute([$country['id']]);
$cities = $stmt->fetchAll();

$risks = country_risks((int) $country['id']);
$visa = visa_for_current_user($country);
$currency = (string) $country['currency_code'];
$target = display_currency();
$rate = $target !== $currency ? fx_convert(1, $currency, $target) : null;

$mapPoints = array_map(fn ($c) => [
    'lat' => (float) $c['lat'], 'lng' => (float) $c['lng'], 'name' => $c['name_en'],
    'note' => $c['places'] . ' places', 'url' => city_url((int) $c['id']), 'kind' => 'city',
], $cities);

$costs = array_filter([
    'Local meal'             => $country['price_meal'],
    'Cup of coffee'          => $country['price_coffee'],
    'Mid-range hotel, night' => $country['price_hotel_night'],
    'Local transport ticket' => $country['price_local_transport'],
], fn ($v) => $v !== null);

$pageTitle = $country['name_en'];
$useMap = true;
require __DIR__ . '/includes/header.php';
?>
<p class="breadcrumb"><a href="<?= e(url()) ?>">Destinations</a></p>

<header class="place-hero">
    <div class="place-hero__flag"><?= flag_img($country['iso2'], 56) ?></div>
    <h1 class="place-hero__title"><?= e($country['name_en']) ?></h1>
    <?php if ($country['name_th']): ?><p class="place-hero__local" lang="th"><?= e($country['name_th']) ?></p><?php endif; ?>
    <?php if ($country['summary']): ?><p class="place-hero__lead"><?= e($country['summary']) ?></p><?php endif; ?>
</header>

<dl class="facts facts--grid">
    <?php foreach ([
        'Capital' => $country['capital'], 'Languages' => $country['languages'],
        'Currency' => $currency . ($rate ? ' (1 ' . $currency . ' ≈ ' . money($rate, $target) . ')' : ''),
        'Time zone' => $country['timezone'], 'Plug types' => $country['plug_types'], 'Best time to visit' => $country['best_season'],
    ] as $label => $value): ?>
        <?php if ($value): ?><div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div><?php endif; ?>
    <?php endforeach; ?>
</dl>

<div class="layout-split">
    <div class="layout-split__main">
        <section class="section" aria-labelledby="cities-title">
            <h2 id="cities-title" class="section-title">Where to go</h2>
            <?= map_block('map-country', $mapPoints, 'Map of cities in ' . $country['name_en'], 6) ?>
            <ul class="card-list">
                <?php foreach ($cities as $c): ?>
                    <li class="card">
                        <h3 class="card__title"><a href="<?= e(city_url((int) $c['id'])) ?>"><?= e($c['name_en']) ?></a>
                            <span class="card__local" lang="th"><?= e($c['name_th'] ?? '') ?></span></h3>
                        <p class="card__text"><?= e($c['summary'] ?? '') ?></p>
                        <p class="card__meta"><?= (int) $c['places'] ?> places · <?= e(admin_options('environment')[$c['environment']] ?? '') ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="section prose" aria-labelledby="know-title">
            <h2 id="know-title" class="section-title">Know before you go</h2>
            <?php foreach (['History' => 'history', 'Culture' => 'culture', 'Beliefs and religion' => 'beliefs', 'Etiquette' => 'etiquette'] as $h => $col): ?>
                <?php if (!empty($country[$col])): ?>
                    <h3><?= e($h) ?></h3><?= paragraphs($country[$col]) ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </section>
    </div>

    <aside class="layout-split__side">
        <section class="panel" aria-labelledby="visa-title">
            <h2 id="visa-title" class="panel__title">Visa for you</h2>
            <?php if ($visa['state'] === 'guest'): ?>
                <p><a href="<?= e(url('login.php?next=' . rawurlencode((string) $_SERVER['REQUEST_URI']))) ?>">Log in</a> to see the visa rules for your passport.</p>
            <?php elseif ($visa['state'] === 'no_passport'): ?>
                <p>Add your nationality in <a href="<?= e(url('profile.php')) ?>">your profile</a> to see visa rules.</p>
            <?php elseif ($visa['state'] === 'home'): ?>
                <p>This is your home country, so you don't need a visa.</p>
            <?php elseif ($visa['state'] === 'unknown'): ?>
                <p>We don't have visa information for <?= e($visa['passport']) ?> passports yet. Check with the embassy of <?= e($country['name_en']) ?> before you book.</p>
            <?php else: $v = $visa['rule']; ?>
                <p class="visa visa--<?= e($v['visa_type']) ?>"><?= e(RJ_VISA_TYPES[$v['visa_type']] ?? $v['visa_type']) ?>
                    <?php if ($v['max_stay_days']): ?><span>up to <?= (int) $v['max_stay_days'] ?> days</span><?php endif; ?></p>
                <p class="muted">For <?= e($v['passport_name']) ?> passport holders<?= $v['fee_text'] ? ' · Fee: ' . e($v['fee_text']) : '' ?></p>
                <?= paragraphs($v['summary']) ?>
                <?php if ($v['how_to_apply']): ?><details><summary>How to apply</summary><?= paragraphs($v['how_to_apply']) ?></details><?php endif; ?>
                <p class="small">
                    <?php if ($v['official_url']): ?><a href="<?= e($v['official_url']) ?>" rel="noopener" target="_blank">Official source</a> · <?php endif; ?>
                    <?= $v['verified_at'] ? 'Checked ' . e(date('j M Y', strtotime($v['verified_at']))) : 'Not yet verified' ?>.
                    Rules change, so confirm before you travel.
                </p>
            <?php endif; ?>
        </section>

        <?php if ($risks['rows']): ?>
        <section class="panel" aria-labelledby="safety-title">
            <h2 id="safety-title" class="panel__title">Safety</h2>
            <?php if ($risks['overall'] !== null): ?>
                <p class="overall-risk">Overall risk <strong><?= number_format($risks['overall'], 1) ?></strong> / 5
                    <?= level_meter((int) round($risks['overall']), RJ_LEVEL_WORDS[(int) round($risks['overall'])] ?? '') ?></p>
            <?php endif; ?>
            <ul class="risk-list">
                <?php foreach ($risks['rows'] as $r): ?>
                    <li>
                        <details>
                            <summary>
                                <span class="risk-list__name"><?= e(RJ_RISK_LABELS[$r['risk_type']]) ?></span>
                                <?= level_meter((int) $r['score'], RJ_LEVEL_WORDS[(int) $r['score']]) ?>
                            </summary>
                            <?= paragraphs($r['description']) ?>
                            <?php if ($r['how_to_handle']): ?><p><strong>What to do:</strong> <?= e($r['how_to_handle']) ?></p><?php endif; ?>
                        </details>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($country['advisory_level']): ?>
                <p class="small">Official advisory: level <?= (int) $country['advisory_level'] ?> of 4,
                    <?= e(admin_options('advisory')[(int) $country['advisory_level']] ?? '') ?>
                    <?php if ($country['advisory_url']): ?>(<a href="<?= e($country['advisory_url']) ?>" rel="noopener" target="_blank">source</a>)<?php endif; ?>.</p>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="panel" aria-labelledby="emergency-title">
            <h2 id="emergency-title" class="panel__title">Emergency numbers</h2>
            <dl class="numbers">
                <?php foreach (['Police' => 'emergency_police', 'Ambulance' => 'emergency_ambulance', 'Fire' => 'emergency_fire', 'Tourist police' => 'tourist_police'] as $label => $col): ?>
                    <?php if (!empty($country[$col])): ?><div><dt><?= e($label) ?></dt><dd><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $country[$col])) ?>"><?= e($country[$col]) ?></a></dd></div><?php endif; ?>
                <?php endforeach; ?>
            </dl>
        </section>

        <?php if ($costs): ?>
        <section class="panel" aria-labelledby="cost-title">
            <h2 id="cost-title" class="panel__title">Cost of living</h2>
            <?php if ($country['cost_index']): ?>
                <p><?= level_meter((int) $country['cost_index'], RJ_COST_WORDS[(int) $country['cost_index']]) ?> <?= e(RJ_COST_WORDS[(int) $country['cost_index']]) ?></p>
            <?php endif; ?>
            <dl class="costs">
                <?php foreach ($costs as $label => $amount): ?>
                    <div><dt><?= e($label) ?></dt><dd><?= e(price_pair((float) $amount, $currency)) ?></dd></div>
                <?php endforeach; ?>
            </dl>
            <p class="small">Typical prices. <?= fx_rates() ? 'Exchange rates from ' . e(fx_rates()['source']) . '.' : 'Live exchange rates are unavailable right now.' ?></p>
        </section>
        <?php endif; ?>
    </aside>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
