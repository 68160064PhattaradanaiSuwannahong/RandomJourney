<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

// Logout only by POST with a CSRF token, so a link or image on another
// site can't log people out.
if (!is_post()) {
    redirect('index.php');
}

verify_csrf();
logout_user();
flash('info', 'You have logged out.');
redirect('login.php');
