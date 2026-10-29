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
    // When adding Leaflet later, add its CDN host to script-src and style-src.
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com; "
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

/** Stop the request if the POSTed token is missing or wrong. */
function verify_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Form expired</title>'
           . '<body style="font-family:sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem">'
           . '<h1>This form has expired</h1>'
           . '<p>Go back, reload the page and submit the form again.</p></body>';
        exit;
    }
}
