<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

// GET normally; POST when the search uses your location, so it never ends up in a URL or server log
$in = is_post() ? $_POST : $_GET;
$f = read_filters($in);
$submitted = isset($in['go']);
$results = $submitted ? recommend($f) : [];
$scopeLabel = scope_label($f);

// Post/Redirect/Get: a location search is kept in the session (without the location: distances are
// already worked out) and shown on a plain GET page, so Back never asks to resend the form.
if (is_post()) {
    $kept = array_merge($f, ['lat' => null, 'lng' => null]);
    if ($kept['home_source'] === 'location') {
        $kept['home'] = $kept['home_source'] = null;
    }
    $searchId = 's' . bin2hex(random_bytes(6));
    $searches = $_SESSION['rj_search'] ?? [];
    $searches[$searchId] = ['filters' => $kept, 'results' => array_slice($results, 0, 40), 'label' => $scopeLabel, 'go' => $submitted];
    $_SESSION['rj_search'] = array_slice($searches, -4, null, true);
    redirect('recommend.php?r=' . $searchId . '#result');
}
$searchId = is_string($_GET['r'] ?? null) ? $_GET['r'] : '';
if ($searchId !== '') {
    $saved = $_SESSION['rj_search'][$searchId] ?? null;
    if (is_array($saved)) {
        ['filters' => $f, 'results' => $results, 'label' => $scopeLabel, 'go' => $submitted] = $saved;
    }
}

$mapPoints = array_map(fn ($p) => [
    'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'], 'name' => $p['name_en'],
    'note' => $p['score'] . '% match, ' . $p['city_name'], 'url' => place_url((int) $p['id']), 'kind' => $p['category_slug'],
], array_slice($results, 0, 10));

$thumbs = first_photos(array_column($results, 'id'));
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
    <form class="tool-form" method="get" data-post-with-location action="<?= e(url('recommend.php') . '#result') ?>">
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
            <?php if ($musts): ?>
                <p>Nothing fits every "Must have" you chose (<?= e(implode(', ', array_map(fn ($c) => strtolower(RJ_CRITERIA[$c]), $musts))) ?>).
                    Change one of them to "Important" to see the closest matches instead.</p>
            <?php else: ?>
                <p>There are no places in <?= e($scopeLabel) ?> for what you're looking for.
                    Choose more countries or cities, or a longer distance.</p>
            <?php endif; ?>
        <?php else: ?>
            <h2 id="results-title" class="section-title"><?= count($results) ?> best <?= count($results) === 1 ? 'match' : 'matches' ?></h2>
            <p class="muted small">Looking in: <?= e($scopeLabel) ?></p>
            <?= map_block('map-results', $mapPoints, 'Map of the top 10 matches', 5) ?>
            <ol class="result-list">
                <?php foreach ($results as $i => $p): ?>
                    <?php $ph = $thumbs[(int) $p['id']] ?? null; ?>
                    <li class="result<?= $ph ? ' result--photo' : '' ?>">
                        <div class="result__score" aria-label="<?= (int) $p['score'] ?>% match">
                            <span class="result__rank">#<?= $i + 1 ?></span>
                            <span class="result__pct"><?= (int) $p['score'] ?>%</span>
                        </div>
                        <?php if ($ph): ?><a class="result__photo" href="<?= e(place_url((int) $p['id'])) ?>" tabindex="-1" aria-hidden="true"><?= photo_img($ph, 'thumb', '') ?></a><?php endif; ?>
                        <div class="result__body">
                            <h3 class="card__title"><a href="<?= e(place_url((int) $p['id'])) ?>"><?= e($p['name_en']) ?></a></h3>
                            <p class="card__meta"><?= flag_img($p['iso2'], 18) ?> <?= e($p['city_name']) ?>, <?= e($p['country_name']) ?>,
                                <?= e($p['category_name']) ?>, <?= e(RJ_PRICE_WORDS[(int) $p['price_level']]) ?><?php
                                if ($p['distance'] !== null): ?>, <?= e(format_distance($p['distance'])) ?> from you<?php endif; ?>
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
