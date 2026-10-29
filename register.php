<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect('profile.php');
}

$errors = [];
$old = [
    'username'               => '',
    'display_name'           => '',
    'email'                  => '',
    'residence_country_id'   => null,
    'nationality_country_id' => null,
];

if (is_post()) {
    verify_csrf();

    $input = [
        'username'               => post_str('username'),
        'display_name'           => post_str('display_name'),
        'email'                  => post_str('email'),
        'password'               => (string) ($_POST['password'] ?? ''),
        'password_confirm'       => (string) ($_POST['password_confirm'] ?? ''),
        'residence_country_id'   => post_int('residence_country_id'),
        'nationality_country_id' => post_int('nationality_country_id'),
    ];
    $old = array_intersect_key($input, $old);

    $result = register_user($input);
    $errors = $result['errors'];

    if (!$errors) {
        login_user($result['user_id']);
        flash('success', 'Your account is ready. Welcome to Random Journey!');
        redirect('profile.php');
    }
}

$pageTitle = 'Sign up';
$bodyClass = 'page-auth';
require __DIR__ . '/includes/header.php';
?>
<section class="envelope" aria-labelledby="register-title">
    <div class="envelope__body">
        <h1 id="register-title" class="envelope__title">Create your account</h1>
        <p class="envelope__lead">Your country and nationality let us show visa rules and prices that apply to you.</p>

        <?= form_errors_summary($errors) ?>

        <form method="post" action="<?= e(url('register.php')) ?>" novalidate>
            <?= csrf_field() ?>

            <div class="field">
                <label for="username">Username</label>
                <input id="username" name="username" type="text" required autocomplete="username"
                       minlength="3" maxlength="30" pattern="[A-Za-z0-9_]+"
                       value="<?= e($old['username']) ?>"<?= field_aria($errors, 'username') ?>>
                <p class="field__hint">3–30 letters, numbers or underscores. You'll use it to log in.</p>
                <?= field_error($errors, 'username') ?>
            </div>

            <div class="field">
                <label for="display_name">Display name <span class="optional">(optional)</span></label>
                <input id="display_name" name="display_name" type="text" maxlength="60" autocomplete="nickname"
                       value="<?= e($old['display_name']) ?>"<?= field_aria($errors, 'display_name') ?>>
                <p class="field__hint">Shown on your reviews instead of your username.</p>
                <?= field_error($errors, 'display_name') ?>
            </div>

            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" required maxlength="190" autocomplete="email"
                       value="<?= e($old['email']) ?>"<?= field_aria($errors, 'email') ?>>
                <?= field_error($errors, 'email') ?>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="residence_country_id">Country you live in</label>
                    <select id="residence_country_id" name="residence_country_id" required<?= field_aria($errors, 'residence_country_id') ?>>
                        <option value="">Choose a country</option>
                        <?= country_options($old['residence_country_id']) ?>
                    </select>
                    <?= field_error($errors, 'residence_country_id') ?>
                </div>

                <div class="field">
                    <label for="nationality_country_id">Nationality</label>
                    <select id="nationality_country_id" name="nationality_country_id" required<?= field_aria($errors, 'nationality_country_id') ?>>
                        <option value="">Choose a country</option>
                        <?= country_options($old['nationality_country_id']) ?>
                    </select>
                    <?= field_error($errors, 'nationality_country_id') ?>
                </div>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="password-input">
                    <input id="password" name="password" type="password" required autocomplete="new-password"
                           minlength="<?= RJ_PASSWORD_MIN ?>" maxlength="<?= RJ_PASSWORD_MAX ?>"<?= field_aria($errors, 'password') ?>>
                    <button type="button" class="password-toggle" data-toggle-password="password" aria-pressed="false">Show</button>
                </div>
                <p class="field__hint">At least <?= RJ_PASSWORD_MIN ?> characters. A short phrase is easier to remember.</p>
                <?= field_error($errors, 'password') ?>
            </div>

            <div class="field">
                <label for="password_confirm">Repeat password</label>
                <input id="password_confirm" name="password_confirm" type="password" required autocomplete="new-password"
                       maxlength="<?= RJ_PASSWORD_MAX ?>"<?= field_aria($errors, 'password_confirm') ?>>
                <?= field_error($errors, 'password_confirm') ?>
            </div>

            <button type="submit" class="button button--wide">Create account</button>
        </form>

        <p class="envelope__switch">Already have an account? <a href="<?= e(url('login.php')) ?>">Log in</a></p>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
