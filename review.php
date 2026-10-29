<?php
/**
 * Review actions (POST only): save, delete, helpful, report, admin hide.
 * Always redirects back to the place page.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

if (!is_post()) {
    redirect('index.php');
}
verify_csrf();
$user = require_login();

$placeId = post_int('place_id');
$place = $placeId ? find_place($placeId) : null;
if ($place === null) {
    flash('error', 'That place no longer exists.');
    redirect('index.php');
}
$back = 'place.php?id=' . $placeId;
$action = post_str('action');

/** Load a review by id, or null. */
$loadReview = static function (?int $id) use ($placeId): ?array {
    if ($id === null) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM rj_reviews WHERE id = ? AND place_id = ?');
    $stmt->execute([$id, $placeId]);
    return $stmt->fetch() ?: null;
};

switch ($action) {
    case 'save':
        $result = validate_review($_POST);
        if ($result['errors']) {
            // Show the form again with the user's text and the errors
            $_SESSION['review_form'] = ['place_id' => $placeId, 'form' => $result['form'], 'errors' => $result['errors']];
            redirect($back . '#write-review');
        }
        $v = $result['values'];
        $existing = my_review($placeId);
        if ($existing) {
            db()->prepare('UPDATE rj_reviews SET rating = ?, title = ?, body = ?, visited_at = ? WHERE id = ?')
                ->execute([$v['rating'], $v['title'], $v['body'], $v['visited_at'], $existing['id']]);
            flash('success', 'Review updated.');
        } else {
            db()->prepare('INSERT INTO rj_reviews (user_id, place_id, rating, title, body, visited_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$user['id'], $placeId, $v['rating'], $v['title'], $v['body'], $v['visited_at']]);
            flash('success', 'Thanks! Your review is published.');
        }
        refresh_place_rating($placeId);
        redirect($back . '#reviews');

    case 'delete':
        $review = my_review($placeId);
        if ($review) {
            db()->prepare('DELETE FROM rj_reviews WHERE id = ?')->execute([$review['id']]);
            refresh_place_rating($placeId);
            flash('success', 'Your review was deleted.');
        }
        redirect($back . '#reviews');

    case 'helpful':
        $review = $loadReview(post_int('review_id'));
        if ($review && (int) $review['user_id'] !== (int) $user['id'] && $review['status'] === 'visible') {
            $stmt = db()->prepare('DELETE FROM rj_review_helpful WHERE user_id = ? AND review_id = ?');
            $stmt->execute([$user['id'], $review['id']]);
            if ($stmt->rowCount() === 0) {
                db()->prepare('INSERT IGNORE INTO rj_review_helpful (user_id, review_id) VALUES (?, ?)')->execute([$user['id'], $review['id']]);
            }
            db()->prepare('UPDATE rj_reviews SET helpful_count = (SELECT COUNT(*) FROM rj_review_helpful WHERE review_id = ?) WHERE id = ?')
                ->execute([$review['id'], $review['id']]);
        }
        redirect($back . '#review-' . ($review['id'] ?? ''));

    case 'report':
        $review = $loadReview(post_int('review_id'));
        $reason = post_str('reason');
        if (!$review || (int) $review['user_id'] === (int) $user['id']) {
            redirect($back . '#reviews');
        }
        if (!array_key_exists($reason, RJ_REPORT_REASONS)) {
            flash('error', 'Choose why you are reporting this review.');
            redirect($back . '#review-' . $review['id']);
        }
        db()->prepare('INSERT IGNORE INTO rj_review_reports (review_id, reporter_id, reason, details) VALUES (?, ?, ?, ?)')
            ->execute([$review['id'], $user['id'], $reason, mb_substr(post_str('details'), 0, 500) ?: null]);
        flash('success', 'Thanks for reporting. An admin will check this review.');
        redirect($back . '#review-' . $review['id']);

    case 'hide':
        // Admin shortcut from the place page
        require_admin();
        $review = $loadReview(post_int('review_id'));
        if ($review) {
            db()->prepare("UPDATE rj_reviews SET status = 'hidden' WHERE id = ?")->execute([$review['id']]);
            db()->prepare("UPDATE rj_review_reports SET status = 'resolved', resolved_by = ?, resolved_at = NOW() WHERE review_id = ? AND status = 'open'")
                ->execute([$user['id'], $review['id']]);
            refresh_place_rating($placeId);
            flash('success', 'Review hidden. You can restore it from Admin → Reports.');
        }
        redirect($back . '#reviews');
}

redirect($back);
