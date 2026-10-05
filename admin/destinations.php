<?php
/**
 * admin/destinations.php - add / edit destinations (delete only when no packages use it).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_admin();

$errors = [];
$edit = null;
if ($editId = int_param($_GET, 'edit')) {
    $stmt = db()->prepare('SELECT * FROM destinations WHERE id = ?');
    $stmt->execute([$editId]);
    $edit = $stmt->fetch() ?: null;
}

if (is_post()) {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'delete') {
        $id = int_param($_POST, 'id');
        $stmt = db()->prepare('SELECT COUNT(*) FROM packages WHERE destination_id = ?');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            set_flash('error', 'This destination still has packages. Remove or move them first.');
        } else {
            db()->prepare('DELETE FROM destinations WHERE id = ?')->execute([$id]);
            set_flash('success', 'Destination deleted.');
        }
        redirect('admin/destinations.php');
    }

    $f = [
        'name'        => trim($_POST['name'] ?? ''),
        'region'      => trim($_POST['region'] ?? ''),
        'label'       => trim($_POST['label'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'image'       => basename($_POST['image'] ?? ''),
        'is_featured' => empty($_POST['is_featured']) ? 0 : 1,
    ];
    if (mb_strlen($f['name']) < 2 || mb_strlen($f['name']) > 100) $errors[] = 'Enter a name.';
    [$uploadError, $uploaded] = save_uploaded_image($_FILES['image_upload'] ?? null);
    if ($uploadError) $errors[] = $uploadError;
    if ($uploaded) $f['image'] = $uploaded;

    if (!$errors) {
        $id = int_param($_POST, 'id');
        if ($id) {
            db()->prepare('UPDATE destinations SET name=?, region=?, label=?, description=?, image=?, is_featured=? WHERE id=?')->execute([...array_values($f), $id]);
        } else {
            db()->prepare('INSERT INTO destinations (name, region, label, description, image, is_featured) VALUES (?, ?, ?, ?, ?, ?)')->execute(array_values($f));
        }
        set_flash('success', 'Destination saved.');
        redirect('admin/destinations.php');
    }
    $edit = array_merge($edit ?? [], $f, ['id' => int_param($_POST, 'id')]);
}

$list = db()->query('SELECT d.*, (SELECT COUNT(*) FROM packages p WHERE p.destination_id = d.id) AS package_count FROM destinations d ORDER BY d.name')->fetchAll();

$pageTitle = 'Admin: Destinations';
$activeNav = 'admin/index.php';
require_once __DIR__ . '/../includes/header.php';
$d = $edit ?? [];
?>

<div class="container">
    <div class="page-head"><h1>Destinations</h1></div>
    <?= admin_nav('destinations.php') ?>
    <?= render_flash() ?>

    <div class="layout-sidebar">
        <section class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Name</th><th>Region</th><th>Packages</th><th>Featured</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($list as $row): ?>
                        <tr>
                            <td><a href="<?= url('destination.php?id=' . $row['id']) ?>"><?= e($row['name']) ?></a></td>
                            <td><?= e($row['region']) ?></td>
                            <td><?= (int) $row['package_count'] ?></td>
                            <td><?= $row['is_featured'] ? 'Yes' : '–' ?></td>
                            <td class="actions">
                                <a href="<?= url('admin/destinations.php?edit=' . $row['id']) ?>">Edit</a>
                                <?php if (!$row['package_count']): ?>
                                    <form method="post" data-confirm="Delete <?= e($row['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $row['id'] ?>"><button class="link-button text-danger" name="action" value="delete">Delete</button></form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <form class="card" method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) ($d['id'] ?? 0) ?>">
            <h2 class="card-title"><?= !empty($d['id']) ? 'Edit destination' : 'Add destination' ?></h2>
            <?php if ($errors): ?><?= alert('error', implode(' ', $errors)) ?><?php endif; ?>
            <div class="form-group"><label for="name">Name</label><input class="input" id="name" name="name" value="<?= e($d['name'] ?? '') ?>" required></div>
            <div class="form-group"><label for="region">Region</label><input class="input" id="region" name="region" value="<?= e($d['region'] ?? '') ?>"></div>
            <div class="form-group"><label for="label">Badge label</label><input class="input" id="label" name="label" value="<?= e($d['label'] ?? '') ?>" placeholder="e.g. Coastal"></div>
            <div class="form-group"><label for="description">Description</label><textarea class="input" id="description" name="description" rows="3"><?= e($d['description'] ?? '') ?></textarea></div>
            <div class="form-group"><label for="image">Photo</label>
                <select class="input" id="image" name="image"><?php foreach (builtin_images() as $img): ?><option value="<?= e($img) ?>" <?= ($d['image'] ?? '') === $img ? 'selected' : '' ?>><?= e($img) ?></option><?php endforeach; ?></select>
                <input class="input" style="margin-top:8px" type="file" name="image_upload" accept="image/jpeg,image/png" aria-label="Upload a new photo"></div>
            <label class="check form-group"><input type="checkbox" name="is_featured" value="1" <?= !empty($d['is_featured']) ? 'checked' : '' ?>> <span>Featured on the home page</span></label>
            <button class="btn btn-primary btn-block" type="submit">Save</button>
            <?php if (!empty($d['id'])): ?><a class="btn btn-light btn-block" style="margin-top:8px" href="<?= url('admin/destinations.php') ?>">Cancel</a><?php endif; ?>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
