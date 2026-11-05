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
    <script src="<?= e(asset('js/theme.js')) ?>"></script>
    <meta name="color-scheme" content="light dark">
    <meta name="rj-csrf-url" content="<?= e(url('csrf.php')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/fonts.css')) ?>">
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
<canvas class="dust" data-dust aria-hidden="true"></canvas>

<header class="site-header">
    <div class="wrap site-header__inner">
        <a class="brand" href="<?= e(url()) ?>">
            <svg class="brand__mark" viewBox="0 0 100 100" aria-hidden="true" focusable="false">
                <circle cx="50" cy="50" r="44"/><path d="M50 6v12M50 82v12M6 50h12M82 50h12"/>
                <path class="brand__needle" d="M50 18 58 50 50 82 42 50Z"/>
            </svg>
            <span class="brand__name">Random Journey</span>
        </a>
        <nav class="site-nav" aria-label="Main">
            <?= $navLink('index.php', 'Destinations') ?>
            <?= $navLink('recommend.php', 'Recommend') ?>
            <?= $navLink('random.php', 'Spin') ?>
            <?= $navLink('deck.php', 'Deck') ?>
            <?php if ($user): ?><?= $navLink('trips.php', 'Trips', in_array($currentPage, ['trips.php', 'trip.php'], true)) ?><?php endif; ?>
            <button type="button" class="loc-pin" data-location-toggle aria-pressed="false"
                    data-title-on="Location is on: tap to turn it off" data-title-off="Use my location for distances" title="Use my location for distances">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7Zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5Z"/></svg>
                <span data-location-status data-short>Off</span><span class="visually-hidden"> (my location)</span>
            </button>
            <span class="nav-tools">
                <button type="button" class="icon-toggle" data-theme-toggle aria-pressed="false" aria-label="Night mode">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path class="icon-moon" d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z"/><g class="icon-flame"><path d="M12 3c2 3 4 4.5 4 7.5a4 4 0 0 1-8 0C8 8.5 10 7 12 3Z"/><path d="M10 17h4v4h-4z"/></g></svg>
                </button>
                <button type="button" class="icon-toggle" data-sound-toggle aria-pressed="false" aria-label="Sound effects">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 9h4l5-4v14l-5-4H4z"/><path class="icon-waves" d="M16 8.5a5 5 0 0 1 0 7M18.5 6a8.5 8.5 0 0 1 0 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                </button>
            </span>
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
<?php foreach (take_flashes() as $flash): ?>
    <p class="notice notice--<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></p>
<?php endforeach; ?>
