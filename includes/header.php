<?php
/**
 * Page header. Set $pageTitle (and optionally $bodyClass) before including.
 */
declare(strict_types=1);

if (!defined('RJ_BOOT')) { http_response_code(403); exit; }

$user = current_user();
$appName = (string) config('app.name', 'Random Journey');
$fullTitle = isset($pageTitle) && $pageTitle !== '' ? $pageTitle . ' – ' . $appName : $appName;
// Path of the current script relative to the site root, e.g. "admin/list.php"
$basePath = rtrim((string) config('app.base_url', ''), '/') . '/';
$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
$currentPage = str_starts_with($scriptName, $basePath) ? substr($scriptName, strlen($basePath)) : basename($scriptName);
$navLink = static fn (string $file, string $label, ?bool $current = null): string => sprintf(
    '<a href="%s"%s>%s</a>',
    e(url($file)),
    ($current ?? $currentPage === $file) ? ' aria-current="page"' : '',
    e($label)
);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($fullTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@125,700;125,800&family=IBM+Plex+Sans+Thai:wght@400;500;600&display=swap">
    <?php if (!empty($useMap)): ?>
        <link rel="stylesheet" href="<?= e(asset('vendor/leaflet/leaflet.css')) ?>">
        <script src="<?= e(asset('vendor/leaflet/leaflet.js')) ?>" defer></script>
        <script src="<?= e(asset('js/map.js')) ?>" defer></script>
    <?php endif; ?>
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<a class="skip-link" href="#main">Skip to content</a>

<header class="site-header">
    <div class="wrap site-header__inner">
        <a class="brand" href="<?= e(url()) ?>">Random Journey</a>
        <nav class="site-nav" aria-label="Main">
            <?= $navLink('index.php', 'Destinations') ?>
            <?= $navLink('recommend.php', 'Recommend') ?>
            <?= $navLink('random.php', 'Spin') ?>
            <?php if ($user): ?>
                <?php if ($user['role'] === 'admin'): ?>
                    <?= $navLink('admin/index.php', 'Admin', str_starts_with($currentPage, 'admin/')) ?>
                <?php endif; ?>
                <?= $navLink('profile.php', 'Profile') ?>
                <form class="inline-form" method="post" action="<?= e(url('logout.php')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="link-button">Log out</button>
                </form>
            <?php else: ?>
                <?= $navLink('login.php', 'Log in') ?>
                <a class="button button--small" href="<?= e(url('register.php')) ?>">Sign up</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main id="main" class="wrap">
<?php foreach (take_flashes() as $f): ?>
    <p class="notice notice--<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></p>
<?php endforeach; ?>
