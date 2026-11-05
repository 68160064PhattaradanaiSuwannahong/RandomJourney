<?php
/**
 * Filter fields shared by recommend.php and random.php.
 * Expects $f (from read_filters) and $mode ('recommend' or 'random').
 */
declare(strict_types=1);

if (!defined('RJ_BOOT')) { http_response_code(403); exit; }

$isRecommend = $mode === 'recommend';

/** How much a criterion matters on the recommend form: a ring (doesn't matter) and three gilt diamonds. */
$importance = static function (string $c) use ($f, $isRecommend): string {
    if (!$isRecommend) {
        return '';
    }
    $html = '<span class="weight" role="radiogroup" aria-label="How much ' . e(strtolower(RJ_CRITERIA[$c])) . ' matters">';
    foreach (RJ_IMPORTANCE as $v => $label) {
        $html .= '<label class="weight__pip" title="' . e($label) . '"><input type="radio" name="imp_' . e($c) . '" value="' . $v . '"'
               . ($f['imp'][$c] === $v ? ' checked' : '') . '><span class="visually-hidden">' . e($label) . '</span></label>';
    }
    return $html . '</span>';
};

$byCountry = [];
foreach (destination_cities() as $id => $c) {
    $byCountry[$c['iso2']][$id] = $c['name'];
}
$user = current_user();
$homeNames = destination_list();
?>
<fieldset class="filter-group">
    <legend>Where and what</legend>

    <details class="picker"<?= $f['countries'] ? ' open' : '' ?>>
        <summary class="picker__summary">
            <span class="field__label">Countries</span>
            <span class="picker__count" data-picker-count="countries[]" data-empty="Anywhere"><?=
                $f['countries'] ? count($f['countries']) . ' chosen' : 'Anywhere' ?></span>
        </summary>
        <div class="chips chips--countries" data-country-chips>
            <?php foreach ($homeNames as $iso => $name): ?>
                <label class="chip chip--flag"><input type="checkbox" name="countries[]" value="<?= e($iso) ?>"<?=
                    in_array($iso, $f['countries'], true) ? ' checked' : '' ?>> <?= flag_img($iso, 20) ?> <?= e($name) ?></label>
            <?php endforeach; ?>
        </div>
    </details>

    <details class="picker"<?= $f['cities'] ? ' open' : '' ?>>
        <summary class="picker__summary">
            <span class="field__label">Cities</span>
            <span class="picker__count" data-picker-count="cities[]" data-empty="Any city"><?=
                $f['cities'] ? count($f['cities']) . ' chosen' : 'Any city' ?></span>
        </summary>
        <div class="city-groups">
            <?php foreach ($byCountry as $iso => $cities): ?>
                <div class="city-group" data-city-country="<?= e($iso) ?>">
                    <p class="city-group__name"><?= flag_img($iso, 16) ?> <?= e($homeNames[$iso] ?? $iso) ?></p>
                    <div class="chips">
                        <?php foreach ($cities as $id => $name): ?>
                            <label class="chip"><input type="checkbox" name="cities[]" value="<?= (int) $id ?>"<?=
                                in_array((int) $id, $f['cities'], true) ? ' checked' : '' ?>> <?= e($name) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="field__hint">Ticking a city narrows only its own country: Japan with Tokyo, plus Italy, means Tokyo or anywhere in Italy.</p>
    </details>

    <div class="field field--check-inline">
        <label><input type="checkbox" name="not_home" value="1"<?= $f['not_home'] ? ' checked' : '' ?>>
            Leave out the country I live in<?php if ($f['home_source'] === 'profile'): ?>
                (<?= e($homeNames[$f['home']] ?? country_name_of($f['home'])) ?>)<?php endif; ?></label>
        <?php if (!$user || !$user['residence_country_id']): ?>
            <div class="field field--inline">
                <label for="home">I live in</label>
                <select id="home" name="home">
                    <option value="">Use my location, or choose…</option>
                    <?php foreach ($homeNames as $iso => $name): ?>
                        <option value="<?= e($iso) ?>"<?= $f['home_source'] === 'chosen' && $f['home'] === $iso ? ' selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($f['not_home'] && $f['home_source'] === 'location'): ?>
                <p class="field__hint">Your location is in <?= e($homeNames[$f['home']] ?? $f['home']) ?>, so it's left out.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

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

<fieldset class="filter-group filter-group--location" data-location-panel hidden>
    <legend>Near me</legend>
    <div class="location-row">
        <button type="button" class="switch" data-location-toggle aria-pressed="false">
            <span class="switch__track" aria-hidden="true"><span class="switch__thumb"></span></span>
            <span class="switch__label">Use my location</span>
        </button>
        <span class="location-status" data-location-status role="status"><?= $f['lat'] !== null ? 'On' : 'Off' ?></span>
    </div>
    <input type="hidden" name="lat" value="<?= $f['lat'] !== null ? e((string) $f['lat']) : '' ?>" data-loc-lat>
    <input type="hidden" name="lng" value="<?= $f['lng'] !== null ? e((string) $f['lng']) : '' ?>" data-loc-lng>
    <div class="field">
        <label for="radius">How far from me</label>
        <select id="radius" name="radius">
            <option value="">Any distance</option>
            <?php foreach (RJ_RADII as $km => $label): ?>
                <option value="<?= $km ?>"<?= $f['radius'] === $km ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <p class="field__hint">Your position is rounded to about 1 km, used only for this search and never saved.
        Turn it off any time here or with the pin at the top of the page.</p>
</fieldset>

<fieldset class="filter-group">
    <legend>Budget and safety</legend>
    <div class="field">
        <div class="field__head"><label class="field__label" for="max_price">Highest price level</label><?= $importance('budget') ?></div>
        <div class="field__row">
            <select id="max_price" name="max_price">
                <option value="">Any price</option>
                <?php foreach (RJ_PRICE_WORDS as $v => $label): ?>
                    <option value="<?= $v ?>"<?= $f['max_price'] === $v ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="field">
        <div class="field__head"><label class="field__label" for="max_risk">Highest country risk</label><?= $importance('safety') ?></div>
        <div class="field__row">
            <select id="max_risk" name="max_risk">
                <option value="">Any risk</option>
                <?php foreach ([2 => 'Low (up to 2/5)', 3 => 'Medium (up to 3/5)', 4 => 'High (up to 4/5)'] as $v => $label): ?>
                    <option value="<?= $v ?>"<?= $f['max_risk'] === $v ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="field">
        <div class="field__head"><label class="field__label" for="cost">Highest cost of living</label><?= $importance('cost') ?></div>
        <div class="field__row">
            <select id="cost" name="cost">
                <option value="">Any</option>
                <?php foreach (RJ_COST_WORDS as $v => $label): ?>
                    <?php $label = $v === 1 ? $label . ' only' : 'Up to ' . strtolower($label); ?>
                    <option value="<?= $v ?>"<?= $f['cost'] === $v ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php if (current_user()): ?>
        <?php if ($isRecommend): ?>
            <div class="field">
                <div class="field__head"><span class="field__label">Easy visa for my passport</span><?= $importance('visa') ?></div>
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
    <div class="field">
        <div class="field__head"><span class="field__label" id="env-label">City or nature</span><?= $importance('environment') ?></div>
        <div class="field__row">
            <div class="segmented" role="radiogroup" aria-labelledby="env-label">
                <?php foreach (['' => 'Either', 'urban' => 'City', 'nature' => 'Nature'] as $v => $label): ?>
                    <label><input type="radio" name="environment" value="<?= e($v) ?>"<?= $f['environment'] === $v ? ' checked' : '' ?>> <?= e($label) ?></label>
                <?php endforeach; ?>
            </div>
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
