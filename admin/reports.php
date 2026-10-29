<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

if (is_post()) {
    verify_csrf();
    $reviewId = post_int('review_id');
    $action = post_str('action');

    $stmt = db()->prepare('SELECT id, place_id FROM rj_reviews WHERE id = ?');
    $stmt->execute([$reviewId ?? 0]);
    $review = $stmt->fetch();

    if ($review) {
        switch ($action) {
            case 'hide':
                db()->prepare("UPDATE rj_reviews SET status = 'hidden' WHERE id = ?")->execute([$review['id']]);
                db()->prepare("UPDATE rj_review_reports SET status = 'resolved', resolved_by = ?, resolved_at = NOW() WHERE review_id = ? AND status = 'open'")
                    ->execute([$me['id'], $review['id']]);
                flash('success', 'Review hidden and its reports closed.');
                break;
            case 'keep':
                db()->prepare("UPDATE rj_review_reports SET status = 'dismissed', resolved_by = ?, resolved_at = NOW() WHERE review_id = ? AND status = 'open'")
                    ->execute([$me['id'], $review['id']]);
                flash('success', 'Reports dismissed. The review stays visible.');
                break;
            case 'restore':
                db()->prepare("UPDATE rj_reviews SET status = 'visible' WHERE id = ?")->execute([$review['id']]);
                flash('success', 'Review restored.');
                break;
            case 'delete':
                db()->prepare('DELETE FROM rj_reviews WHERE id = ?')->execute([$review['id']]);
                flash('success', 'Review deleted permanently.');
                break;
        }
        refresh_place_rating((int) $review['place_id']);
    }
    redirect('admin/reports.php');
}

// Open reports, one row per review
$open = db()->query(
    "SELECT r.id, r.rating, r.title, r.body, r.created_at, p.id AS place_id, p.name_en AS place_name,
            COALESCE(u.display_name, u.username) AS author,
            COUNT(rp.id) AS report_count,
            GROUP_CONCAT(DISTINCT rp.reason) AS reasons,
            GROUP_CONCAT(NULLIF(rp.details, '') SEPARATOR ' | ') AS details
     FROM rj_review_reports rp
     JOIN rj_reviews r ON r.id = rp.review_id
     JOIN rj_places p ON p.id = r.place_id
     JOIN rj_users u ON u.id = r.user_id
     WHERE rp.status = 'open'
     GROUP BY r.id
     ORDER BY report_count DESC, MIN(rp.created_at)"
)->fetchAll();

$hidden = db()->query(
    "SELECT r.id, r.rating, r.title, r.body, p.id AS place_id, p.name_en AS place_name, COALESCE(u.display_name, u.username) AS author
     FROM rj_reviews r JOIN rj_places p ON p.id = r.place_id JOIN rj_users u ON u.id = r.user_id
     WHERE r.status = 'hidden' ORDER BY r.updated_at DESC LIMIT 100"
)->fetchAll();

$button = static function (int $reviewId, string $action, string $label, string $style, ?string $confirm = null): string {
    return '<form method="post" action="' . e(url('admin/reports.php')) . '" class="inline-form"'
        . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field()
        . '<input type="hidden" name="review_id" value="' . $reviewId . '">'
        . '<input type="hidden" name="action" value="' . e($action) . '">'
        . '<button type="submit" class="button button--small ' . $style . '">' . e($label) . '</button></form>';
};

$pageTitle = 'Reported reviews – Admin';
$bodyClass = 'page-admin';
require dirname(__DIR__) . '/includes/header.php';
echo admin_nav('reports');
?>
<h1 class="page-title">Reported reviews</h1>

<?php if (!$open): ?>
    <p class="empty">No open reports. Nice and quiet.</p>
<?php else: ?>
    <ul class="moderation-list">
        <?php foreach ($open as $r): ?>
            <li class="panel">
                <p class="moderation-list__meta small">
                    <strong><?= (int) $r['report_count'] ?> <?= (int) $r['report_count'] === 1 ? 'report' : 'reports' ?>:</strong>
                    <?= e(implode(', ', array_map(fn ($k) => RJ_REPORT_REASONS[$k] ?? $k, explode(',', $r['reasons'])))) ?>
                </p>
                <?php if ($r['details']): ?><p class="small muted">Reporter notes: <?= e($r['details']) ?></p><?php endif; ?>
                <blockquote class="moderation-list__review">
                    <p><span class="stars"><?= stars((float) $r['rating']) ?></span> <strong><?= e($r['title'] ?? '') ?></strong></p>
                    <?= paragraphs($r['body']) ?>
                    <footer class="small muted">By <?= e($r['author']) ?> on
                        <a href="<?= e(place_url((int) $r['place_id'])) ?>#review-<?= (int) $r['id'] ?>"><?= e($r['place_name']) ?></a></footer>
                </blockquote>
                <div class="form-actions">
                    <?= $button((int) $r['id'], 'hide', 'Hide review', 'button--danger') ?>
                    <?= $button((int) $r['id'], 'keep', 'Keep it, dismiss reports', 'button--quiet') ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<section class="section" aria-labelledby="hidden-title">
    <h2 id="hidden-title" class="section-title">Hidden reviews</h2>
    <?php if (!$hidden): ?>
        <p class="empty">No hidden reviews.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th scope="col">Review</th><th scope="col">Place</th><th scope="col">Author</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($hidden as $h): ?>
                    <tr>
                        <th scope="row"><span class="stars"><?= stars((float) $h['rating']) ?></span>
                            <?= e($h['title'] ?? mb_strimwidth($h['body'], 0, 60, '…')) ?></th>
                        <td><a href="<?= e(place_url((int) $h['place_id'])) ?>"><?= e($h['place_name']) ?></a></td>
                        <td><?= e($h['author']) ?></td>
                        <td class="row-actions">
                            <?= $button((int) $h['id'], 'restore', 'Restore', 'button--quiet') ?>
                            <?= $button((int) $h['id'], 'delete', 'Delete', 'button--danger', 'Delete this review permanently?') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
