<?php
/**
 * Random Journey configuration.
 *
 * 1. Copy this file to config.php (same folder) and fill in the real values.
 * 2. Upload it with the rest of the site. The line below makes it answer
 *    403 if someone opens it directly in a browser.
 * 3. Never commit config.php to git.
 */
if (!defined('RJ_BOOT')) { http_response_code(403); exit; }

return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 's68160064',
        'user'    => 's68160064',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    'app' => [
        'name'     => 'Random Journey',
        // URL path of the site, no trailing slash
        'base_url' => '/~68160064/RandomJourney',
        'timezone' => 'Asia/Bangkok',
        // true = show PHP errors on screen (local development only)
        'debug'    => false,
    ],

    'security' => [
        // Lock a username after this many failed logins inside the window
        'max_failed_per_user' => 5,
        // Lock an IP address after this many failed logins inside the window
        'max_failed_per_ip'   => 20,
        'lockout_minutes'     => 15,
        // Log the user out after this many minutes without activity
        'idle_timeout_minutes' => 120,
    ],
];
