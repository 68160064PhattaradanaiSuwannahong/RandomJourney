<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$user = require_login();

$profileErrors = [];
$passwordErrors = [];

if (is_post()) {
    verify_csrf();
    $form = post_str('form');

    if ($form === 'profile') {
        $displayName   = post_str('display_name');
        $residenceId   = post_int('residence_country_id');
        $nationalityId = post_int('nationality_country_id');
        $currency      = strtoupper(post_str('preferred_currency'));
        $bio           = post_str('bio');

        if (mb_strlen($displayName) > 60) {
            $profileErrors['display_name'] = 'Use at most 60 characters.';
        }
        if (!country_exists($residenceId)) {
            $profileErrors['residence_country_id'] = 'Choose the country you live in.';
        }
        if (!country_exists($nationalityId)) {
            $profileErrors['nationality_country_id'] = 'Choose your nationality.';
        }
        if (!in_array($currency, currencies_for_select(), true)) {
            $profileErrors['preferred_currency'] = 'Choose a currency from the list.';
        }
        if (mb_strlen($bio) > 500) {
            $profileErrors['bio'] = 'Use at most 500 characters.';
        }

        if (!$profileErrors) {
            db()->prepare(
                'UPDATE rj_users
                 SET display_name = ?, residence_country_id = ?, nationality_country_id = ?,
                     preferred_currency = ?, bio = ?
                 WHERE id = ?'
            )->execute([
                $displayName !== '' ? $displayName : null,
                $residenceId,
                $nationalityId,
                $currency,
                $bio !== '' ? $bio : null,
                $user['id'],
            ]);
            flash('success', 'Profile saved.');
            redirect('profile.php');
        }

        // Re-show what the user typed
        $user = array_merge($user, [
            'display_name'           => $displayName,
            'residence_country_id'   => $residenceId,
            'nationality_country_id' => $nationalityId,
            'preferred_currency'     => $currency,
            'bio'                    => $bio,
        ]);
    }

    if ($form === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        $stmt = db()->prepare('SELECT password_hash FROM rj_users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $hash = (string) $stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            $passwordErrors['current_password'] = 'Your current password is incorrect.';
        } else {
            $passwordErrors = validate_new_password($new, $confirm, 'new_password');
            if (!$passwordErrors && password_verify($new, $hash)) {
                $passwordErrors['new_password'] = 'Choose a password different from your current one.';
            }
        }

        if (!$passwordErrors) {
            db()->prepare('UPDATE rj_users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, RJ_PASSWORD_ALGO), $user['id']]);
            session_regenerate_id(true);
            flash('success', 'Password changed.');
            redirect('profile.php');
        }
    }
}

$stmt = db()->prepare('SELECT iso2, name_en FROM rj_countries WHERE id = ?');
$stmt->execute([$user['nationality_country_id']]);
$nationality = $stmt->fetch() ?: null;

$stmt = db()->prepare(
    'SELECT p.id, p.name_en, ci.name_en AS city_name, co.iso2, cat.name_en AS category_name
     FROM rj_favorites f JOIN rj_places p ON p.id = f.place_id
     JOIN rj_cities ci ON ci.id = p.city_id JOIN rj_countries co ON co.id = ci.country_id
     JOIN rj_categories cat ON cat.id = p.category_id
     WHERE f.user_id = ? ORDER BY f.created_at DESC'
);
$stmt->execute([$user['id']]);
$savedPlaces = $stmt->fetchAll();

$stmt = db()->prepare(
    'SELECT r.id, r.rating, r.title, r.status, r.created_at, r.helpful_count, p.id AS place_id, p.name_en
     FROM rj_reviews r JOIN rj_places p ON p.id = r.place_id WHERE r.user_id = ? ORDER BY r.created_at DESC'
);
$stmt->execute([$user['id']]);
$myReviews = $stmt->fetchAll();

$pageTitle = 'Your profile';
require __DIR__ . '/includes/header.php';
?>
<div class="profile">
    <header class="profile__head">
        <h1 class="page-title"><?= e($user['display_name'] ?: $user['username']) ?></h1>
        <dl class="facts">
            <div><dt>Username</dt><dd><?= e($user['username']) ?></dd></div>
            <div><dt>Email</dt><dd><?= e($user['email']) ?></dd></div>
            <?php if ($nationality): ?>
                <div><dt>Passport</dt><dd><?= flag_img($nationality['iso2'], 22) ?> <?= e($nationality['name_en']) ?></dd></div>
            <?php endif; ?>
            <div><dt>Member since</dt><dd><?= e(date('j M Y', strtotime($user['created_at']))) ?></dd></div>
            <?php if ($user['role'] === 'admin'): ?>
                <div><dt>Role</dt><dd>Admin</dd></div>
            <?php endif; ?>
        </dl>
    </header>

    <section class="panel" id="saved" aria-labelledby="saved-title">
        <h2 id="saved-title" class="panel__title">Saved places</h2>
        <?php if (!$savedPlaces): ?>
            <p class="muted">Nothing saved yet. Press ♡ Save on any place to build your wishlist.</p>
        <?php else: ?>
            <ul class="nearby-list">
                <?php foreach ($savedPlaces as $sp): ?>
                    <li>
                        <span><a href="<?= e(place_url((int) $sp['id'])) ?>"><?= e($sp['name_en']) ?></a>
                            <span class="muted small"><?= flag_img($sp['iso2'], 16) ?> <?= e($sp['city_name']) ?>, <?= e($sp['category_name']) ?></span></span>
                        <form method="post" action="<?= e(url('favorite.php')) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="place_id" value="<?= (int) $sp['id'] ?>">
                            <input type="hidden" name="back" value="profile">
                            <button type="submit" class="link-button">Remove</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php if ($myReviews): ?>
    <section class="panel" aria-labelledby="myreviews-title">
        <h2 id="myreviews-title" class="panel__title">Your reviews</h2>
        <ul class="nearby-list">
            <?php foreach ($myReviews as $mr): ?>
                <li>
                    <a href="<?= e(place_url((int) $mr['place_id'])) ?>#write-review"><?= e($mr['name_en']) ?></a>
                    <span class="muted small"><span class="stars"><?= stars((float) $mr['rating']) ?></span>
                        <?= $mr['status'] === 'hidden' ? '<span class="badge badge--warn">Hidden by admin</span>' : (int) $mr['helpful_count'] . ' helpful' ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <section class="panel" aria-labelledby="details-title">
        <h2 id="details-title" class="panel__title">Travel details</h2>
        <p class="panel__lead">Used to show visa rules for your passport and prices in your currency.</p>

        <?= form_errors_summary($profileErrors) ?>

        <form method="post" action="<?= e(url('profile.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="profile">

            <div class="field">
                <label for="display_name">Display name</label>
                <input id="display_name" name="display_name" type="text" maxlength="60"
                       value="<?= e($user['display_name'] ?? '') ?>"<?= field_aria($profileErrors, 'display_name') ?>>
                <?= field_error($profileErrors, 'display_name') ?>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="residence_country_id">Country you live in</label>
                    <select id="residence_country_id" name="residence_country_id" data-currency-target="preferred_currency"<?= field_aria($profileErrors, 'residence_country_id') ?>>
                        <option value="">Choose a country</option>
                        <?= country_options($user['residence_country_id'] !== null ? (int) $user['residence_country_id'] : null) ?>
                    </select>
                    <?= field_error($profileErrors, 'residence_country_id') ?>
                </div>

                <div class="field">
                    <label for="nationality_country_id">Nationality</label>
                    <select id="nationality_country_id" name="nationality_country_id"<?= field_aria($profileErrors, 'nationality_country_id') ?>>
                        <option value="">Choose a country</option>
                        <?= country_options($user['nationality_country_id'] !== null ? (int) $user['nationality_country_id'] : null) ?>
                    </select>
                    <?= field_error($profileErrors, 'nationality_country_id') ?>
                </div>
            </div>

            <div class="field field--short">
                <label for="preferred_currency">Show prices in</label>
                <select id="preferred_currency" name="preferred_currency"<?= field_aria($profileErrors, 'preferred_currency') ?>>
                    <?php foreach (currencies_for_select() as $code): ?>
                        <option value="<?= e($code) ?>"<?= $code === $user['preferred_currency'] ? ' selected' : '' ?>><?= e($code) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error($profileErrors, 'preferred_currency') ?>
            </div>

            <div class="field">
                <label for="bio">About you <span class="optional">(optional)</span></label>
                <textarea id="bio" name="bio" rows="3" maxlength="500"<?= field_aria($profileErrors, 'bio') ?>><?= e($user['bio'] ?? '') ?></textarea>
                <p class="field__hint">Shown on your public reviews. Up to 500 characters.</p>
                <?= field_error($profileErrors, 'bio') ?>
            </div>

            <button type="submit" class="button">Save profile</button>
        </form>
    </section>

    <section class="panel" aria-labelledby="password-title">
        <h2 id="password-title" class="panel__title">Change password</h2>

        <?= form_errors_summary($passwordErrors) ?>

        <form method="post" action="<?= e(url('profile.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="password">
            <input type="text" name="username" value="<?= e($user['username']) ?>" autocomplete="username" hidden>

            <div class="field">
                <label for="current_password">Current password</label>
                <input id="current_password" name="current_password" type="password" required autocomplete="current-password"<?= field_aria($passwordErrors, 'current_password') ?>>
                <?= field_error($passwordErrors, 'current_password') ?>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="new_password">New password</label>
                    <input id="new_password" name="new_password" type="password" required autocomplete="new-password"
                           minlength="<?= RJ_PASSWORD_MIN ?>" maxlength="<?= RJ_PASSWORD_MAX ?>"<?= field_aria($passwordErrors, 'new_password') ?>>
                    <?= field_error($passwordErrors, 'new_password') ?>
                </div>
                <div class="field">
                    <label for="new_password_confirm">Repeat new password</label>
                    <input id="new_password_confirm" name="new_password_confirm" type="password" required autocomplete="new-password"
                           maxlength="<?= RJ_PASSWORD_MAX ?>"<?= field_aria($passwordErrors, 'new_password_confirm') ?>>
                    <?= field_error($passwordErrors, 'new_password_confirm') ?>
                </div>
            </div>

            <button type="submit" class="button button--quiet">Change password</button>
        </form>
    </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
