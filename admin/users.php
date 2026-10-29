<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_admin();

if (is_post()) {
    verify_csrf();
    $userId = post_int('user_id');
    $action = post_str('action');

    if ($userId === null || $userId === (int) $me['id']) {
        // Prevents locking yourself out of the admin panel
        flash('error', 'You can\'t change your own role or suspend your own account.');
        redirect('admin/users.php');
    }

    $sql = match ($action) {
        'make_admin' => "UPDATE rj_users SET role = 'admin' WHERE id = ?",
        'make_user'  => "UPDATE rj_users SET role = 'user' WHERE id = ?",
        'suspend'    => 'UPDATE rj_users SET is_active = 0 WHERE id = ?',
        'restore'    => 'UPDATE rj_users SET is_active = 1 WHERE id = ?',
        default      => null,
    };
    if ($sql !== null) {
        db()->prepare($sql)->execute([$userId]);
        flash('success', match ($action) {
            'make_admin' => 'User is now an admin.',
            'make_user'  => 'Admin rights removed.',
            'suspend'    => 'Account suspended. The user is logged out on their next click.',
            'restore'    => 'Account restored.',
        });
    }
    redirect('admin/users.php');
}

$q = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT u.id, u.username, u.email, u.display_name, u.role, u.is_active, u.created_at, u.last_login_at,
               c.name_en AS nationality,
               (SELECT COUNT(*) FROM rj_reviews r WHERE r.user_id = u.id) AS reviews
        FROM rj_users u LEFT JOIN rj_countries c ON c.id = u.nationality_country_id';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE u.username LIKE ? OR u.email LIKE ? OR u.display_name LIKE ?';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $params = [$like, $like, $like];
}
$sql .= ' ORDER BY u.created_at DESC LIMIT 500';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$pageTitle = 'Users – Admin';
$bodyClass = 'page-admin';
require dirname(__DIR__) . '/includes/header.php';
echo admin_nav('users');

$actionButton = static function (array $u, string $action, string $label, string $style = 'button--quiet'): string {
    return '<form method="post" action="' . e(url('admin/users.php')) . '" class="inline-form">'
        . csrf_field()
        . '<input type="hidden" name="user_id" value="' . (int) $u['id'] . '">'
        . '<input type="hidden" name="action" value="' . e($action) . '">'
        . '<button type="submit" class="button button--small ' . $style . '">' . e($label) . '</button></form>';
};
?>
<h1 class="page-title">Users</h1>

<form class="toolbar" method="get" action="<?= e(url('admin/users.php')) ?>">
    <label for="q" class="visually-hidden">Search users</label>
    <input id="q" type="search" name="q" value="<?= e($q) ?>" placeholder="Username, email or name">
    <button type="submit" class="button button--quiet">Search</button>
</form>

<div class="table-wrap">
    <table class="data-table">
        <thead>
        <tr>
            <th scope="col">Username</th>
            <th scope="col">Email</th>
            <th scope="col">Passport</th>
            <th scope="col">Reviews</th>
            <th scope="col">Joined</th>
            <th scope="col">Last login</th>
            <th scope="col">Role</th>
            <th scope="col"><span class="visually-hidden">Actions</span></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <?php $isMe = (int) $u['id'] === (int) $me['id']; ?>
            <tr<?= (int) $u['is_active'] ? '' : ' class="is-suspended"' ?>>
                <th scope="row">
                    <?= e($u['username']) ?>
                    <?php if ($u['display_name']): ?><span class="muted">(<?= e($u['display_name']) ?>)</span><?php endif; ?>
                    <?php if ($isMe): ?><span class="badge">You</span><?php endif; ?>
                    <?php if (!(int) $u['is_active']): ?><span class="badge badge--warn">Suspended</span><?php endif; ?>
                </th>
                <td data-label="Email"><?= e($u['email']) ?></td>
                <td data-label="Passport"><?= admin_display('nationality', $u['nationality']) ?></td>
                <td data-label="Reviews"><?= (int) $u['reviews'] ?></td>
                <td data-label="Joined"><?= e(date('j M Y', strtotime($u['created_at']))) ?></td>
                <td data-label="Last login"><?= $u['last_login_at'] ? e(date('j M Y H:i', strtotime($u['last_login_at']))) : '<span class="muted">Never</span>' ?></td>
                <td data-label="Role"><?= $u['role'] === 'admin' ? '<strong>Admin</strong>' : 'User' ?></td>
                <td class="row-actions">
                    <?php if (!$isMe): ?>
                        <?= $u['role'] === 'admin'
                            ? $actionButton($u, 'make_user', 'Remove admin')
                            : $actionButton($u, 'make_admin', 'Make admin') ?>
                        <?= (int) $u['is_active']
                            ? $actionButton($u, 'suspend', 'Suspend', 'button--danger')
                            : $actionButton($u, 'restore', 'Restore') ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
