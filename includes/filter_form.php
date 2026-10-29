<?php
/**
 * Filter fields shared by recommend.php and random.php.
 * Expects $f (from read_filters) and $mode ('recommend' or 'random').
 */
declare(strict_types=1);

if (!defined('RJ_BOOT')) { http_response_code(403); exit; }

$isRecommend = $mode === 'recommend';

/** Importance dropdown shown next to each criterion on the recommend form. */
$importance = static function (string $c) use ($f, $isRecommend): string {
    if (!$isRecommend) {
        return '';
    }
    $html = '<label class="importance"><span class="visually-hidden">How much ' . e(strtolower(RJ_CRITERIA[$c])) . ' matters</span>'
          . '<select name="imp_' . e($c) . '">';
    foreach (RJ_IMPORTANCE as $v => $label) {
        $html .= '<option value="' . $v . '"' . ($f['imp'][$c] === $v ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html . '</select></label>';
};

$cities = [];
if ($f['country'] !== '') {
    $stmt = db()->prepare('SELECT ci.id, ci.name_en FROM rj_cities ci JOIN rj_countries co ON co.id = ci.country_id WHERE co.iso2 = ? ORDER BY ci.name_en');
    $stmt->execute([$f['country']]);
    $cities = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}
?>
<fieldset class="filter-group">
    <legend>Where and what</legend>
    <div class="field">
        <label for="country">Country</label>
        <select id="country" name="country" data-autosubmit>
            <option value="">Anywhere</option>
            <?php foreach (destination_list() as $iso => $name): ?>
                <option value="<?= e($iso) ?>"<?= $f['country'] === $iso ? ' selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($cities): ?>
        <div class="field">
            <label for="city_id">City</label>
            <select id="city_id" name="city_id">
                <option value="">Any city</option>
                <?php foreach ($cities as $id => $name): ?>
                    <option value="<?= (int) $id ?>"<?= $f['city_id'] === (int) $id ? ' selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <?php if ($isRecommend || ($tripMode ?? 'one') === 'one'): ?>
    <div class="field">
        <label for="group">Looking for</label>
        <select id="group" name="group">
            <option value="">Anything</option>
            <?php foreach (RJ_GROUPS as $key => $g): ?>
                <option value="<?= e($key) ?>"<?= $f['group'] === $key ? ' selected' : '' ?>><?= e($g['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
</fieldset>

<fieldset class="filter-group">
    <legend>Budget and safety</legend>
    <div class="field field--with-importance">
        <label for="max_price">Highest price level</label>
        <div class="field__row">
            <select id="max_price" name="max_price">
                <option value="">Any price</option>
                <?php foreach (RJ_PRICE_WORDS as $v => $label): ?>
                    <option value="<?= $v ?>"<?= $f['max_price'] === $v ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $importance('budget') ?>
        </div>
    </div>
    <div class="field field--with-importance">
        <label for="max_risk">Highest country risk</label>
        <div class="field__row">
            <select id="max_risk" name="max_risk">
                <option value="">Any risk</option>
                <?php foreach ([2 => 'Low (up to 2/5)', 3 => 'Medium (up to 3/5)', 4 => 'High (up to 4/5)'] as $v => $label): ?>
                    <option value="<?= $v ?>"<?= $f['max_risk'] === $v ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $importance('safety') ?>
        </div>
    </div>
    <div class="field field--with-importance">
        <label for="cost">Highest cost of living</label>
        <div class="field__row">
            <select id="cost" name="cost">
                <option value="">Any</option>
                <?php foreach (RJ_COST_WORDS as $v => $label): ?>
                    <?php $label = $v === 1 ? $label . ' only' : 'Up to ' . strtolower($label); ?>
                    <option value="<?= $v ?>"<?= $f['cost'] === $v ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $importance('cost') ?>
        </div>
    </div>
    <?php if (current_user()): ?>
        <?php if ($isRecommend): ?>
            <div class="field field--with-importance">
                <span class="field__label">Easy visa for my passport</span>
                <div class="field__row"><?= $importance('visa') ?></div>
            </div>
        <?php else: ?>
            <div class="field field--check-inline">
                <label><input type="checkbox" name="imp_visa" value="3"<?= $f['imp']['visa'] === 3 ? ' checked' : '' ?>>
                    Only countries with an easy visa for my passport</label>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</fieldset>

<fieldset class="filter-group">
    <legend>Style of trip</legend>
    <div class="field field--with-importance">
        <span class="field__label" id="env-label">City or nature</span>
        <div class="field__row">
            <div class="segmented" role="radiogroup" aria-labelledby="env-label">
                <?php foreach (['' => 'Either', 'urban' => 'City', 'nature' => 'Nature'] as $v => $label): ?>
                    <label><input type="radio" name="environment" value="<?= e($v) ?>"<?= $f['environment'] === $v ? ' checked' : '' ?>> <?= e($label) ?></label>
                <?php endforeach; ?>
            </div>
            <?= $importance('environment') ?>
        </div>
    </div>

    <div class="field">
        <div class="field__head"><span class="field__label">Landscape</span><?= $importance('terrain') ?></div>
        <div class="chips">
            <?php foreach (RJ_TERRAINS as $v => $label): ?>
                <label class="chip"><input type="checkbox" name="terrain[]" value="<?= e($v) ?>"<?= in_array($v, $f['terrain'], true) ? ' checked' : '' ?>> <?= e($label) ?></label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="field">
        <div class="field__head"><span class="field__label">Themes</span><?= $importance('themes') ?></div>
        <div class="chips">
            <?php foreach (theme_options() as $slug => $label): ?>
                <label class="chip"><input type="checkbox" name="themes[]" value="<?= e($slug) ?>"<?= in_array($slug, $f['themes'], true) ? ' checked' : '' ?>> <?= e($label) ?></label>
            <?php endforeach; ?>
        </div>
        <?php if (!$isRecommend): ?><p class="field__hint">A place only needs one of the chosen themes.</p><?php endif; ?>
    </div>
</fieldset>
