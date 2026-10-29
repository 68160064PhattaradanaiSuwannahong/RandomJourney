<?php
/**
 * Loaded first by every page: config, error handling, DB, session, security.
 */
declare(strict_types=1);

// Only pages may load this file; opening it directly gets a 403
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(403);
    exit;
}

define('RJ_BOOT', true);
define('RJ_WWW', dirname(__DIR__));

// ---------------------------------------------------------------------
// Config: the first file that exists wins
// ---------------------------------------------------------------------
$rjConfigCandidates = array_filter([
    getenv('RJ_CONFIG') ?: null,
    dirname(__DIR__) . '/private/config.php',       // RandomJourney/private/config.php (guards itself with RJ_BOOT)
    dirname(__DIR__, 3) . '/rj_private/config.php', // optional: /home/BUU/<id>/rj_private/config.php, outside public_html
]);

$GLOBALS['rj_config'] = null;
foreach ($rjConfigCandidates as $candidate) {
    if (is_file($candidate) && is_readable($candidate)) {
        $GLOBALS['rj_config'] = require $candidate;
        break;
    }
}
if (!is_array($GLOBALS['rj_config'])) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Configuration file not found or not readable.\n"
       . "Copy private/config.example.php to private/config.php and fill in the database password (see README).";
    exit;
}
unset($rjConfigCandidates, $candidate);

require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/security.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/form.php';
require __DIR__ . '/admin.php';
require __DIR__ . '/services.php';
require __DIR__ . '/catalog.php';
require __DIR__ . '/discover.php';
require __DIR__ . '/reviews.php';

// ---------------------------------------------------------------------
// Errors: log everything, show details only in debug mode
// ---------------------------------------------------------------------
date_default_timezone_set((string) config('app.timezone', 'UTC'));
error_reporting(E_ALL);
ini_set('display_errors', config('app.debug') ? '1' : '0');
ini_set('log_errors', '1');

set_exception_handler(function (Throwable $e): void {
    error_log('[RandomJourney] ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $detail = config('app.debug')
        ? '<pre>' . e($e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine()) . '</pre>'
        : '';
    echo '<!doctype html><meta charset="utf-8"><title>Server error</title>'
       . '<body style="font-family:sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem">'
       . '<h1>Something went wrong on the server</h1>'
       . '<p>The page could not be loaded. Try again in a moment.</p>' . $detail . '</body>';
});

send_security_headers();
start_secure_session();
