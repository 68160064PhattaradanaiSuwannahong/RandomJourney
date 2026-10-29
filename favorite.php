<?php
/**
 * Save / unsave a place (POST only).
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

if (!is_post()) {
    redirect('index.php');
}
verify_csrf();
$user = require_login();

$placeId = post_int('place_id');
if ($placeId === null || find_place($placeId) === null) {
    redirect('index.php');
}

$stmt = db()->prepare('DELETE FROM rj_favorites WHERE user_id = ? AND place_id = ?');
$stmt->execute([$user['id'], $placeId]);
if ($stmt->rowCount() === 0) {
    db()->prepare('INSERT IGNORE INTO rj_favorites (user_id, place_id) VALUES (?, ?)')->execute([$user['id'], $placeId]);
    flash('success', 'Saved to your list. Find it in your profile.');
} else {
    flash('info', 'Removed from your saved places.');
}

// Return to where the button was pressed (profile or place page)
redirect(post_str('back') === 'profile' ? 'profile.php#saved' : 'place.php?id=' . $placeId);
