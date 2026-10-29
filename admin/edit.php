<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_admin();

$key = is_string($_GET['e'] ?? null) ? $_GET['e'] : '';
$entity = admin_entity($key);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$row = null;
if ($id !== null) {
    $row = admin_load($entity, $id);
    if ($row === null || ($key === 'country' && !(int) $row['is_destination'])) {
        flash('error', 'That item no longer exists.');
        redirect('admin/list.php?e=' . $key);
    }
} elseif (!$entity['can_create']) {
    redirect('admin/list.php?e=' . $key);
}

$errors = [];

if (is_post()) {
    verify_csrf();
    $result = admin_validate($entity, $_POST);
    $errors = $result['errors'];
    $form = $result['form'];

    if (!$errors && ($msg = admin_unique_error($entity, $result['values'], $id))) {
        $errors['_form'] = $msg;
    }

    if (!$errors) {
        $savedId = admin_save($entity, $result['values'], $result['tags'], $id);
        flash('success', ucfirst($entity['singular']) . ($id ? ' saved.' : ' added.'));
        $next = post_str('after') === 'new'
            ? 'admin/edit.php?e=' . $key
            : 'admin/edit.php?e=' . $key . '&id=' . $savedId;
        redirect($next);
    }
} elseif ($row !== null) {
    $form = admin_row_to_form($entity, $row);
} else {
    // New item: defaults, plus any filter passed from the list page (e.g. ?city_id=3)
    $form = ['status' => 'published', 'price_level' => '2', 'environment' => 'urban', 'terrain' => 'city'];
    foreach ($entity['fields'] as $f) {
        if (isset($f['name']) && isset($_GET[$f['name']]) && is_string($_GET[$f['name']])) {
            $form[$f['name']] = $_GET[$f['name']];
        }
    }
}

$title = match (true) {
    $row === null              => 'Add ' . $entity['singular'],
    isset($row['name_en'])     => $row['name_en'],
    isset($row['title'])       => $row['title'],
    $key === 'risk'            => 'Risk: ' . (RJ_RISK_TYPES[$row['risk_type']] ?? $row['risk_type']),
    default                    => ucfirst($entity['singular']) . ' #' . $id,
};

$pageTitle = $title . ' – Admin';
$bodyClass = 'page-admin';
require dirname(__DIR__) . '/includes/header.php';
echo admin_nav($key);
?>
<p class="breadcrumb"><a href="<?= e(url('admin/list.php?e=' . $key)) ?>"><?= e($entity['title']) ?></a></p>
<h1 class="page-title"><?= e($title) ?></h1>

<?php if ($row && $entity['children']): ?>
    <p class="related">
        <?php foreach ($entity['children'] as $child): ?>
            <?php $childEntity = admin_entity($child['entity']); ?>
            <a href="<?= e(url('admin/list.php?e=' . $child['entity'] . '&' . $child['filter'] . '=' . $id)) ?>"><?= e($child['label']) ?></a>
            <?php if ($childEntity['can_create']): ?>
                <a class="related__add" href="<?= e(url('admin/edit.php?e=' . $child['entity'] . '&' . $child['filter'] . '=' . $id)) ?>">+ Add</a>
            <?php endif; ?>
        <?php endforeach; ?>
    </p>
<?php endif; ?>

<?php if (isset($errors['_form'])): ?>
    <p class="notice notice--error" role="alert"><?= e($errors['_form']) ?></p>
<?php else: ?>
    <?= form_errors_summary($errors) ?>
<?php endif; ?>

<form class="admin-form" method="post"
      action="<?= e(url('admin/edit.php?e=' . $key . ($id ? '&id=' . $id : ''))) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="admin-form__grid">
        <?php foreach ($entity['fields'] as $f): ?>
            <?= admin_field($f, $form, $errors) ?>
        <?php endforeach; ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="button"><?= $id ? 'Save changes' : 'Add ' . e($entity['singular']) ?></button>
        <?php if (!$id): ?>
            <button type="submit" name="after" value="new" class="button button--quiet">Add and start another</button>
        <?php endif; ?>
        <a href="<?= e(url('admin/list.php?e=' . $key)) ?>">Cancel</a>
    </div>
</form>

<?php if ($id): ?>
    <form class="danger-zone" method="post" action="<?= e(url('admin/delete.php')) ?>"
          data-confirm="<?= e($entity['delete']['type'] === 'unflag'
              ? 'Remove this country from destinations? Its cities and places stay in the database.'
              : 'Delete this ' . $entity['singular'] . ' permanently? This cannot be undone.') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="e" value="<?= e($key) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <button type="submit" class="button button--danger">
            <?= e($entity['delete']['label'] ?? 'Delete ' . $entity['singular']) ?>
        </button>
    </form>
<?php endif; ?>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
