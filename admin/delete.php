<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

if (!is_post()) {
    redirect('admin/index.php');
}
verify_csrf();

$key = post_str('e');
$entity = admin_entity($key);
$id = post_int('id');

if ($id === null) {
    redirect('admin/list.php?e=' . $key);
}

try {
    if ($entity['delete']['type'] === 'unflag') {
        db()->prepare("UPDATE {$entity['table']} SET {$entity['delete']['column']} = 0 WHERE id = ?")->execute([$id]);
        flash('success', 'Removed from destinations.');
    } else {
        db()->prepare("DELETE FROM {$entity['table']} WHERE id = ?")->execute([$id]);
        flash('success', ucfirst($entity['singular']) . ' deleted.');
    }
} catch (PDOException $e) {
    // Foreign key still in use (e.g. a category that places point to)
    if (($e->errorInfo[1] ?? null) === 1451) {
        flash('error', 'This ' . $entity['singular'] . ' is still used by other records, so it can\'t be deleted.');
        redirect('admin/edit.php?e=' . $key . '&id=' . $id);
    }
    throw $e;
}

redirect('admin/list.php?e=' . $key);
