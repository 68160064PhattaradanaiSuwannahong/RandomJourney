<?php
/**
 * Single shared PDO connection.
 */
declare(strict_types=1);

if (!defined('RJ_BOOT')) { http_response_code(403); exit; }

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        config('db.host'),
        config('db.name'),
        config('db.charset', 'utf8mb4')
    );

    $pdo = new PDO($dsn, (string) config('db.user'), (string) config('db.pass'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Real prepared statements: values never get mixed into the SQL text
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Keep NOW() in MySQL consistent with PHP's timezone
    $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");

    return $pdo;
}

/** True if the exception is a duplicate-key error (e.g. username taken). */
function is_duplicate_key(PDOException $e): bool
{
    return ($e->errorInfo[1] ?? null) === 1062;
}
