<?php
/**
 * Hands the page a current form token (JSON). app.js asks for it before
 * submitting a form on a page that has been open a long time, so the
 * visitor doesn't hit "page expired" after a break.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['token' => csrf_token()]);
