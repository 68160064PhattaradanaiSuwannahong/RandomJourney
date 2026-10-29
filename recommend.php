<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$f = read_filters($_GET);
$submitted = isset($_GET['go']);
$results = $submitted ? recommend($f) : [];

$mapPoints = array_map(fn ($p) => [
    'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'], 'name' => $p['name_en'],
    'note' => $p['score'] . '% match, ' . $p['city_name'], 'url' => place_url((int) $p['id']), 'kind' => $p['category_slug'],
], array_slice($results, 0, 10));

$musts = array_keys(array_filter($f['imp'], fn ($v) => $v === 3));
$musts = array_values(array_filter($musts, fn ($c) => criterion_is_set($f, $c)));

$pageTitle = 'Recommendations';
$useMap = $submitted && $results;
$mode = 'recommend';
require __DIR__ . '/includes/header.php';
?>
<header class="tool-head">
    <h1 class="page-title">Find your best match</h1>
    <p class="tool-head__lead">Set what you want, then tell us how much each thing matters. Places are ranked by how well they fit,
        and each result shows why it was picked.</p>
</header>

<div class="tool-layout<?= $submitted ? ' has-result' : '' ?>">
    <form class="tool-form" method="get" action="<?= e(url('recommend.php') . '#result') ?>">
        <input type="hidden" name="go" value="1">
        <?php require __DIR__ . '/includes/filter_form.php'; ?>
        <p class="field__hint">"Must have" removes places that don't fit. The other levels only change the ranking.</p>
        <button type="submit" class="button button--wide">Show recommendations</button>
    </form>

    <section class="tool-results" id="result" aria-labelledby="results-title" aria-live="polite">
        <?php if (!$submitted): ?>
            <h2 id="results-title" class="visually-hidden">Results</h2>
            <div class="empty-state">
                <p class="empty-state__title">Your matches will appear here.</p>
                <p>Not sure where to start? Pick a few themes like History or Scenic Nature and press <strong>Show recommendations</strong>.
                    Prefer a surprise? <a href="<?= e(url('random.php')) ?>">Spin for a random trip</a>.</p>
            </div>
        <?php elseif (!$results): ?>
            <h2 id="results-title" class="section-title">No matches</h2>
            <p>Nothing fits every "Must have" you chose<?= $musts ? ' (' . e(implode(', ', array_map(fn ($c) => strtolower(RJ_CRITERIA[$c]), $musts))) . ')' : '' ?>.
                Change one of them to "Important" to see the closest matches instead.</p>
        <?php else: ?>
            <h2 id="results-title" class="section-title"><?= count($results) ?> best <?= count($results) === 1 ? 'match' : 'matches' ?></h2>
            <?= map_block('map-results', $mapPoints, 'Map of the top 10 matches', 5) ?>
            <ol class="result-list">
                <?php foreach ($results as $i => $p): ?>
                    <li class="result">
                        <div class="result__score" aria-label="<?= (int) $p['score'] ?>% match">
                            <span class="result__rank">#<?= $i + 1 ?></span>
                            <span class="result__pct"><?= (int) $p['score'] ?>%</span>
                        </div>
                        <div class="result__body">
                            <h3 class="card__title"><a href="<?= e(place_url((int) $p['id'])) ?>"><?= e($p['name_en']) ?></a></h3>
                            <p class="card__meta"><?= flag_img($p['iso2'], 18) ?> <?= e($p['city_name']) ?>, <?= e($p['country_name']) ?>,
                                <?= e($p['category_name']) ?>, <?= e(RJ_PRICE_WORDS[(int) $p['price_level']]) ?>
                                <?php if ((int) $p['review_count'] > 0): ?>, ★ <?= number_format((float) $p['avg_rating'], 1) ?><?php endif; ?></p>
                            <p class="card__text"><?= e($p['summary'] ?? '') ?></p>
                            <?php if ($p['reasons']): ?>
                                <ul class="reasons" aria-label="Why it matches">
                                    <?php foreach ($p['reasons'] as $reason): ?><li><?= e($reason) ?></li><?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
