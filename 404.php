<?php
declare(strict_types=1);

// Point a server's ErrorDocument 404 here (Angsila doesn't allow .htaccess, so in-app pages call not_found() directly).
require __DIR__ . '/includes/bootstrap.php';
not_found('page');
