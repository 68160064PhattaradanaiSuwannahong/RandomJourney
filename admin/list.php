<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$key = is_string($_GET['e'] ?? null) ? $_GET['e'] : '';
$entity = admin_entity($key);
$list = $entity['list'];

// "Add destination" turns an existing country into a destination
if ($key === 'country' && is_post() && post_str('action') === 'add_destination') {
    verify_csrf();
    $countryId = post_int('country_id');
    if ($countryId !== null && isset(admin_options('non_destinations')[$countryId])) {
        db()->prepare('UPDATE rj_countries SET is_destination = 1 WHERE id = ?')->execute([$countryId]);
        flash('success', 'Destination added. Fill in its details below.');
        redirect('admin/edit.php?e=country&id=' . $countryId);
    }
    flash('error', 'Choose a country from the list.');
    redirect('admin/list.php?e=country');
}

// Filters and search come from the query string, but only for columns defined above
$where = isset($list['where']) ? [$list['where']] : [];
$params = [];
$active = [];
foreach ($list['filters'] ?? [] as $param => [$column]) {
    $v = filter_input(INPUT_GET, $param, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($v) {
        $where[] = "{$column} = ?";
        $params[] = $v;
        $active[$param] = $v;
    }
}
$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '' && !empty($list['search'])) {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(' . implode(' OR ', array_map(fn ($c) => "{$c} LIKE ?", $list['search'])) . ')';
    array_push($params, ...array_fill(0, count($list['search']), $like));
}

$sql = "SELECT {$list['select']} FROM {$list['from']}"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . " ORDER BY {$list['order']} LIMIT 500";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Links to "add new" pre-fill the active filter (e.g. new place in this city)
$newLink = 'admin/edit.php?e=' . $key . ($active ? '&' . http_build_query($active) : '');

$pageTitle = $entity['title'] . ' – Admin';
$bodyClass = 'page-admin';
require dirname(__DIR__) . '/includes/header.php';
echo admin_nav($key);
?>
<div class="admin-head">
    <h1 class="page-title"><?= e($entity['title']) ?></h1>
    <?php if ($entity['can_create']): ?>
        <a class="button" href="<?= e(url($newLink)) ?>">Add <?= e($entity['singular']) ?></a>
    <?php endif; ?>
</div>

<?php if ($key === 'country'): ?>
    <form class="toolbar" method="post" action="<?= e(url('admin/list.php?e=country')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_destination">
        <label for="add-country" class="visually-hidden">Country to add</label>
        <select id="add-country" name="country_id" required>
            <option value="">Choose a country to add…</option>
            <?php foreach (admin_options('non_destinations') as $id => $name): ?>
                <option value="<?= (int) $id ?>"><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button button--quiet">Add destination</button>
    </form>
<?php endif; ?>

<form class="toolbar" method="get" action="<?= e(url('admin/list.php')) ?>">
    <input type="hidden" name="e" value="<?= e($key) ?>">
    <?php foreach ($list['filters'] ?? [] as $param => [, $source, $allLabel]): ?>
        <label for="filter-<?= e($param) ?>" class="visually-hidden"><?= e($allLabel) ?></label>
        <select id="filter-<?= e($param) ?>" name="<?= e($param) ?>">
            <option value=""><?= e($allLabel) ?></option>
            <?php foreach (admin_options($source) as $id => $name): ?>
                <option value="<?= (int) $id ?>"<?= ($active[$param] ?? null) === (int) $id ? ' selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
    <?php endforeach; ?>
    <?php if (!empty($list['search'])): ?>
        <label for="q" class="visually-hidden">Search</label>
        <input id="q" type="search" name="q" value="<?= e($q) ?>" placeholder="Search…">
    <?php endif; ?>
    <button type="submit" class="button button--quiet">Filter</button>
    <?php if ($active || $q !== ''): ?>
        <a href="<?= e(url('admin/list.php?e=' . $key)) ?>">Clear</a>
    <?php endif; ?>
</form>

<?php if (!$rows): ?>
    <p class="empty">
        <?= $active || $q !== '' ? 'Nothing matches these filters.' : 'Nothing here yet.' ?>
        <?php if ($entity['can_create']): ?>
            <a href="<?= e(url($newLink)) ?>">Add the first <?= e($entity['singular']) ?></a>.
        <?php endif; ?>
    </p>
<?php else: ?>
    <p class="muted"><?= count($rows) ?> <?= count($rows) === 1 ? 'item' : 'items' ?></p>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <?php foreach ($list['columns'] as $label): ?>
                    <th scope="col"><?= e($label) ?></th>
                <?php endforeach; ?>
                <th scope="col"><span class="visually-hidden">Actions</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <?php $first = true; foreach ($list['columns'] as $col => $label): ?>
                        <?php if ($first): $first = false; ?>
                            <th scope="row"><a href="<?= e(url('admin/edit.php?e=' . $key . '&id=' . (int) $row['id'])) ?>"><?= e($row[$col]) ?></a></th>
                        <?php else: ?>
                            <td data-label="<?= e($label) ?>"><?= admin_display($col, $row[$col]) ?></td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <td class="row-actions">
                        <a href="<?= e(url('admin/edit.php?e=' . $key . '&id=' . (int) $row['id'])) ?>">Edit</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
