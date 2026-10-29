<?php
/**
 * Reviews, helpful votes, reports and saved places.
 */
declare(strict_types=1);

if (!defined('RJ_BOOT')) { http_response_code(403); exit; }

const RJ_REVIEW_MIN = 20;
const RJ_REVIEW_MAX = 3000;

const RJ_REPORT_REASONS = [
    'spam'       => 'Spam or advertising',
    'offensive'  => 'Offensive or abusive',
    'false_info' => 'False or dangerous information',
    'off_topic'  => 'Not about this place',
    'other'      => 'Something else',
];

/** Recalculate the cached average rating and count (visible reviews only). */
function refresh_place_rating(int $placeId): void
{
    db()->prepare(
        "UPDATE rj_places SET
            avg_rating   = (SELECT COALESCE(ROUND(AVG(rating), 2), 0) FROM rj_reviews WHERE place_id = ? AND status = 'visible'),
            review_count = (SELECT COUNT(*) FROM rj_reviews WHERE place_id = ? AND status = 'visible')
         WHERE id = ?"
    )->execute([$placeId, $placeId, $placeId]);
}

/** Visible reviews for a place, with author info and whether the viewer marked each helpful. */
function place_reviews(int $placeId, string $sort = 'helpful'): array
{
    $viewer = current_user()['id'] ?? 0;
    $order = $sort === 'newest' ? 'r.created_at DESC' : 'r.helpful_count DESC, r.created_at DESC';
    $stmt = db()->prepare(
        "SELECT r.*, COALESCE(u.display_name, u.username) AS author, co.iso2 AS author_iso, co.name_en AS author_country,
                EXISTS (SELECT 1 FROM rj_review_helpful h WHERE h.review_id = r.id AND h.user_id = ?) AS i_found_helpful,
                EXISTS (SELECT 1 FROM rj_review_reports rp WHERE rp.review_id = r.id AND rp.reporter_id = ?) AS i_reported
         FROM rj_reviews r
         JOIN rj_users u ON u.id = r.user_id
         LEFT JOIN rj_countries co ON co.id = u.nationality_country_id
         WHERE r.place_id = ? AND r.status = 'visible'
         ORDER BY {$order}"
    );
    $stmt->execute([$viewer, $viewer, $placeId]);
    return $stmt->fetchAll();
}

/** The current user's own review of a place (any status), or null. */
function my_review(int $placeId): ?array
{
    $user = current_user();
    if (!$user) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM rj_reviews WHERE place_id = ? AND user_id = ?');
    $stmt->execute([$placeId, $user['id']]);
    return $stmt->fetch() ?: null;
}

/** Star string like ★★★★☆ */
function stars(float $rating): string
{
    $full = (int) round($rating);
    return str_repeat('★', $full) . str_repeat('☆', 5 - $full);
}

/**
 * Validate review form data.
 * @return array{values: array, errors: array}
 */
function validate_review(array $in): array
{
    $errors = [];
    $rating = filter_var($in['rating'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
    if ($rating === false) {
        $errors['rating'] = 'Choose a rating from 1 to 5 stars.';
    }

    $title = trim((string) ($in['title'] ?? ''));
    if (mb_strlen($title) > 150) {
        $errors['title'] = 'Use at most 150 characters.';
    }

    $body = trim((string) ($in['body'] ?? ''));
    $len = mb_strlen($body);
    if ($len < RJ_REVIEW_MIN) {
        $errors['body'] = 'Write at least ' . RJ_REVIEW_MIN . ' characters so others can learn from your visit.';
    } elseif ($len > RJ_REVIEW_MAX) {
        $errors['body'] = 'Use at most ' . RJ_REVIEW_MAX . ' characters.';
    }

    // <input type="month"> sends "2026-09"; stored as the first day of the month
    $visited = trim((string) ($in['visited_at'] ?? ''));
    $visitedDate = null;
    if ($visited !== '') {
        $d = DateTime::createFromFormat('!Y-m', $visited);
        if (!$d || $d->format('Y-m') !== $visited || $d > new DateTime() || (int) $d->format('Y') < 1950) {
            $errors['visited_at'] = 'Choose the month you visited (not in the future).';
        } else {
            $visitedDate = $d->format('Y-m-01');
        }
    }

    return [
        'values' => ['rating' => $rating ?: null, 'title' => $title !== '' ? $title : null, 'body' => $body, 'visited_at' => $visitedDate],
        'errors' => $errors,
        'form'   => ['rating' => $in['rating'] ?? '', 'title' => $title, 'body' => $body, 'visited_at' => $visited],
    ];
}

function is_favorite(int $placeId): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }
    $stmt = db()->prepare('SELECT 1 FROM rj_favorites WHERE user_id = ? AND place_id = ?');
    $stmt->execute([$user['id'], $placeId]);
    return (bool) $stmt->fetchColumn();
}

function open_report_count(): int
{
    return (int) db()->query("SELECT COUNT(DISTINCT review_id) FROM rj_review_reports WHERE status = 'open'")->fetchColumn();
}
