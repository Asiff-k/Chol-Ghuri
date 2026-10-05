<?php
/**
 * admin/tags.php - travel interest tags (used by profiles, groups and searches).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_admin();

$colors = ['green', 'blue', 'yellow', 'grey'];

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $id     = int_param($_POST, 'id');
    $name   = trim($_POST['name'] ?? '');
    $color  = in_array($_POST['color'] ?? '', $colors, true) ? $_POST['color'] : 'green';

    try {
        if ($action === 'delete') {
            db()->prepare('DELETE FROM tags WHERE id = ?')->execute([$id]);
            set_flash('success', 'Tag deleted (it was removed from profiles and groups too).');
        } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 50) {
            set_flash('error', 'Tag names must be 2–50 characters.');
        } elseif ($action === 'update') {
            db()->prepare('UPDATE tags SET name = ?, color = ? WHERE id = ?')->execute([$name, $color, $id]);
            set_flash('success', 'Tag updated.');
        } else {
            db()->prepare('INSERT INTO tags (name, color) VALUES (?, ?)')->execute([$name, $color]);
            set_flash('success', 'Tag added.');
        }
    } catch (PDOException $ex) {
        if ($ex->errorInfo[1] !== 1062) throw $ex;
        set_flash('error', 'A tag with that name already exists.');
    }
    redirect('admin/tags.php');
}

$tags = db()->query(
    'SELECT t.*, (SELECT COUNT(*) FROM user_tags ut WHERE ut.tag_id = t.id) AS users,
            (SELECT COUNT(*) FROM group_tags gt WHERE gt.tag_id = t.id) AS `groups`
     FROM tags t ORDER BY t.name'
)->fetchAll();

$pageTitle = 'Admin: Tags';
$activeNav = 'admin/index.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="page-head"><h1>Interest Tags</h1></div>
    <?= admin_nav('tags.php') ?>
    <?= render_flash() ?>

    <section class="card">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Tag</th><th>Students</th><th>Groups</th><th>Edit</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($tags as $t): ?>
                    <tr>
                        <td><span class="tag tag-<?= e($t['color']) ?>"><?= e($t['name']) ?></span></td>
                        <td><?= (int) $t['users'] ?></td>
                        <td><?= (int) $t['groups'] ?></td>
                        <td>
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?><input type="hidden" name="id" value="<?= $t['id'] ?>"><input type="hidden" name="action" value="update">
                                <input class="input input-sm" name="name" value="<?= e($t['name']) ?>" aria-label="Tag name">
                                <select class="input input-sm" name="color" aria-label="Colour"><?php foreach ($colors as $c): ?><option <?= $t['color'] === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select>
                                <button class="btn btn-light btn-sm" type="submit">Save</button>
                            </form>
                        </td>
                        <td><form method="post" data-confirm="Delete the tag <?= e($t['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="link-button text-danger" name="action" value="delete">Delete</button></form></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="post" class="inline-form" style="margin-top:20px">
            <?= csrf_field() ?><input type="hidden" name="action" value="add">
            <input class="input input-sm" name="name" placeholder="New tag, e.g. Camping" aria-label="New tag name">
            <select class="input input-sm" name="color" aria-label="Colour"><?php foreach ($colors as $c): ?><option><?= $c ?></option><?php endforeach; ?></select>
            <button class="btn btn-primary btn-sm" type="submit"><?= icon('plus', 'icon-sm') ?> Add Tag</button>
        </form>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
