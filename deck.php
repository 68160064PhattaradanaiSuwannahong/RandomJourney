<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$order = deck_order();
$have = collected_countries();
$count = count(array_intersect($order, $have));
$total = count($order);
$user = current_user();

$pageTitle = 'Your deck';
require __DIR__ . '/includes/header.php';
?>
<header class="tool-head deck-head">
    <h1 class="page-title">Your deck</h1>
    <p class="tool-head__lead">One card for every destination. A card turns face up the first time the dice lands on its country.</p>
    <div class="deck-progress" role="img" aria-label="<?= $count ?> of <?= $total ?> cards collected">
        <span class="deck-progress__count"><?= $count ?></span><span class="deck-progress__total">/ <?= $total ?></span>
        <progress class="deck-progress__bar" value="<?= $count ?>" max="<?= max(1, $total) ?>"></progress>
    </div>
    <?php if (!$user): ?>
        <p class="small muted">You're not logged in, so these cards only last for this visit.
            <a href="<?= e(url('register.php')) ?>">Create an account</a> to keep your deck.</p>
    <?php endif; ?>
    <p><a class="button button--spin" href="<?= e(url('random.php')) ?>">Roll the dice</a></p>
</header>

<ul class="deck">
    <?php foreach ($order as $iso): $up = in_array($iso, $have, true); ?>
        <li class="deck__slot">
            <?= tarot_card($iso, $up
                ? ['size' => 'sm', 'link' => country_url($iso), 'tilt' => true]
                : ['size' => 'sm', 'up' => false]) ?>
            <p class="deck__caption"><?= $up ? e(card_numeral($iso)) . ' · ' . e(destination_list()[$iso]) : e(card_numeral($iso)) . ' · Not drawn yet' ?></p>
        </li>
    <?php endforeach; ?>
</ul>
<?php require __DIR__ . '/includes/footer.php'; ?>
