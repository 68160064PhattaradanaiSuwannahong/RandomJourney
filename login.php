<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$next = $_POST['next'] ?? $_GET['next'] ?? null;
$next = is_string($next) ? $next : null;

if (is_logged_in()) {
    redirect(safe_next($next));
}

$error = null;
$identifier = '';

if (is_post()) {
    verify_csrf();

    $identifier = post_str('identifier');
    $result = attempt_login($identifier, (string) ($_POST['password'] ?? ''));

    if ($result['ok']) {
        $name = current_user(true)['display_name'] ?? null;
        flash('success', $name ? "Welcome back, {$name}." : 'Welcome back.');
        redirect(safe_next($next));
    }
    $error = $result['error'];
}

$pageTitle = 'Log in';
$bodyClass = 'page-auth';
require __DIR__ . '/includes/header.php';
?>
<section class="envelope envelope--narrow" aria-labelledby="login-title">
    <div class="envelope__body">
        <h1 id="login-title" class="envelope__title">Log in</h1>
        <p class="envelope__lead">Pick up where you left off: your saved places, reviews and past spins.</p>

        <?php if ($error): ?>
            <p class="notice notice--error" role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="post" action="<?= e(url('login.php')) ?>">
            <?= csrf_field() ?>
            <?php if ($next): ?>
                <input type="hidden" name="next" value="<?= e($next) ?>">
            <?php endif; ?>

            <div class="field">
                <label for="identifier">Username or email</label>
                <input id="identifier" name="identifier" type="text" required autocomplete="username"
                       autofocus value="<?= e($identifier) ?>">
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="password-input">
                    <input id="password" name="password" type="password" required autocomplete="current-password">
                    <button type="button" class="password-toggle" data-toggle-password="password" aria-pressed="false">Show</button>
                </div>
            </div>

            <button type="submit" class="button button--wide">Log in</button>
        </form>

        <p class="envelope__switch">New here? <a href="<?= e(url('register.php')) ?>">Create an account</a></p>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
