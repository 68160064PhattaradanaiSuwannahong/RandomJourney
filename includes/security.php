<?php
/**
 * HTTP security headers, session setup and CSRF protection.
 */
declare(strict_types=1);

if (!defined('RJ_BOOT')) { http_response_code(403); exit; }

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(self)');

    // Content Security Policy: only load scripts/styles from these sources.
    // Everything (fonts, Leaflet, three.js) is self-hosted; images may come from flagcdn and OpenStreetMap.
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self'; "
        . "font-src 'self'; "
        . "img-src 'self' data: https:; "
        . "connect-src 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'none'; "
        . "base-uri 'self'"
    );
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');   // reject session IDs the server never issued
    ini_set('session.use_only_cookies', '1');  // never accept ?PHPSESSID= in the URL
    ini_set('session.cookie_httponly', '1');

    session_name('RJSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        // Scope the cookie to this app only: other sites on angsila share the domain
        'path'     => rtrim((string) config('app.base_url', ''), '/') . '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Idle timeout
    $timeout = (int) config('security.idle_timeout_minutes', 120) * 60;
    $now = time();
    if (isset($_SESSION['last_seen']) && $now - (int) $_SESSION['last_seen'] > $timeout) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('info', 'You were logged out after a period of inactivity. Log in again to continue.');
    }
    $_SESSION['last_seen'] = $now;
}

// ---------------------------------------------------------------------
// CSRF: every POST form carries a secret token tied to the session
// ---------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** True if the POSTed token matches this session's token. */
function csrf_ok(): bool
{
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $sent);
}

/**
 * Stop the request if the POSTed token is missing or wrong.
 * This usually means the page was left open until the session ended,
 * so explain that and offer a way back instead of a bare error.
 */
function verify_csrf(): void
{
    if (csrf_ok()) {
        return;
    }
    http_response_code(400);
    // Back to the page the form was on (same site only), without its #fragment
    $ref = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    $refPath = ($ref['path'] ?? '') . (isset($ref['query']) ? '?' . $ref['query'] : '');
    $back = safe_next($refPath, 'index.php');
    $pageTitle = 'Page expired';
    require __DIR__ . '/header.php';
    echo '<section class="lost"><p class="lost__code" aria-hidden="true">⌛</p>'
       . '<h1 class="page-title">This page was open for too long</h1>'
       . '<p class="lost__lead">For your security, forms stop working after a while without activity. '
       . 'Nothing was changed. Reload the page and try again.</p>'
       . '<p class="lost__actions">'
       . '<a class="button" href="' . e($back) . '">Go back and reload</a>'
       . '<a class="button button--quiet" href="' . e(url()) . '">Home</a></p></section>';
    require __DIR__ . '/footer.php';
    exit;
}
