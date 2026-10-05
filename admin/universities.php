<?php
/**
 * admin/universities.php - add / edit universities and switch them on or off.
 * The email domain is only enforced when REQUIRE_UNIVERSITY_EMAIL is true.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
require_admin();

$errors = [];
$edit = null;
if ($editId = int_param($_GET, 'edit')) {
    $stmt = db()->prepare('SELECT * FROM universities WHERE id = ?');
    $stmt->execute([$editId]);
    $edit = $stmt->fetch() ?: null;
}

if (is_post()) {
    verify_csrf();
    $f = [
        'name'         => trim($_POST['name'] ?? ''),
        'short_name'   => strtoupper(trim($_POST['short_name'] ?? '')),
        'email_domain' => strtolower(trim($_POST['email_domain'] ?? '')),
        'city'         => trim($_POST['city'] ?? ''),
        'is_active'    => empty($_POST['is_active']) ? 0 : 1,
    ];
    $id = int_param($_POST, 'id');
    if (mb_strlen($f['name']) < 3) $errors[] = 'Enter the university name.';
    if ($f['short_name'] === '' || mb_strlen($f['short_name']) > 20) $errors[] = 'Enter a short name (max 20 characters).';
    if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $f['email_domain'])) $errors[] = 'Enter an email domain like lus.ac.bd.';

    if (!$errors) {
        try {
            if ($id) {
                db()->prepare('UPDATE universities SET name=?, short_name=?, email_domain=?, city=?, is_active=? WHERE id=?')->execute([...array_values($f), $id]);
            } else {
                db()->prepare('INSERT INTO universities (name, short_name, email_domain, city, is_active) VALUES (?, ?, ?, ?, ?)')->execute(array_values($f));
            }
            set_flash('success', 'University saved.');
            redirect('admin/universities.php');
        } catch (PDOException $ex) {
            if ($ex->errorInfo[1] !== 1062) throw $ex;
            $errors[] = 'Another university already uses that email domain.';
        }
    }
    $edit = array_merge($f, ['id' => $id]);
}

$list = db()->query('SELECT un.*, (SELECT COUNT(*) FROM users u WHERE u.university_id = un.id) AS students FROM universities un ORDER BY un.name')->fetchAll();

$pageTitle = 'Admin: Universities';
$activeNav = 'admin/index.php';
require_once __DIR__ . '/../includes/header.php';
$u = $edit ?? [];
?>

<div class="container">
    <div class="page-head"><h1>Universities</h1><p>Strict university-email checking is currently <strong><?= REQUIRE_UNIVERSITY_EMAIL ? 'ON' : 'OFF (any email address can register; the university is self-declared)' ?></strong>. Change it in config/config.php.</p></div>
    <?= admin_nav('universities.php') ?>
    <?= render_flash() ?>

    <div class="layout-sidebar">
        <section class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Name</th><th>Short</th><th>Email domain</th><th>Students</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($list as $row): ?>
                        <tr>
                            <td><?= e($row['name']) ?></td><td><?= e($row['short_name']) ?></td><td><code><?= e($row['email_domain']) ?></code></td>
                            <td><?= (int) $row['students'] ?></td>
                            <td><?= $row['is_active'] ? '<span class="badge">Active</span>' : '<span class="badge badge-grey">Hidden</span>' ?></td>
                            <td><a href="<?= url('admin/universities.php?edit=' . $row['id']) ?>">Edit</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <form class="card" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) ($u['id'] ?? 0) ?>">
            <h2 class="card-title"><?= !empty($u['id']) ? 'Edit university' : 'Add university' ?></h2>
            <?php if ($errors): ?><?= alert('error', implode(' ', $errors)) ?><?php endif; ?>
            <div class="form-group"><label for="name">Name</label><input class="input" id="name" name="name" value="<?= e($u['name'] ?? '') ?>" required></div>
            <div class="form-group"><label for="short_name">Short name</label><input class="input" id="short_name" name="short_name" value="<?= e($u['short_name'] ?? '') ?>" placeholder="e.g. LU" required></div>
            <div class="form-group"><label for="email_domain">Email domain</label><input class="input" id="email_domain" name="email_domain" value="<?= e($u['email_domain'] ?? '') ?>" placeholder="e.g. lus.ac.bd" required></div>
            <div class="form-group"><label for="city">City</label><input class="input" id="city" name="city" value="<?= e($u['city'] ?? '') ?>"></div>
            <label class="check form-group"><input type="checkbox" name="is_active" value="1" <?= ($u['is_active'] ?? 1) ? 'checked' : '' ?>> <span>Shown in the registration list</span></label>
            <button class="btn btn-primary btn-block" type="submit">Save</button>
            <?php if (!empty($u['id'])): ?><a class="btn btn-light btn-block" style="margin-top:8px" href="<?= url('admin/universities.php') ?>">Cancel</a><?php endif; ?>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
