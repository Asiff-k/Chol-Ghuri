<?php
/**
 * admin/index.php - Admin overview: system numbers and user management
 * (block / unblock / verify accounts).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin.php';
$admin = require_admin();

if (is_post()) {
    verify_csrf();
    $userId = int_param($_POST, 'user_id');
    $action = $_POST['action'] ?? '';
    $target = find_user_by_id($userId);

    if (!$target || $target['role'] === 'admin') {
        set_flash('error', 'You can only manage student accounts.');
    } elseif ($action === 'block' || $action === 'unblock') {
        db()->prepare('UPDATE users SET is_blocked = ? WHERE id = ?')->execute([$action === 'block' ? 1 : 0, $userId]);
        set_flash('success', $target['full_name'] . ($action === 'block' ? ' was blocked.' : ' was unblocked.'));
    } elseif ($action === 'verify') {
        db()->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()), verify_token_hash = NULL WHERE id = ?')->execute([$userId]);
        set_flash('success', $target['full_name'] . ' is now verified.');
    }
    redirect('admin/index.php');
}

$counts = [];
foreach ([
    'Students'      => "SELECT COUNT(*) FROM users WHERE role = 'student'",
    'Verified'      => "SELECT COUNT(*) FROM users WHERE role = 'student' AND email_verified_at IS NOT NULL",
    'Groups forming'=> "SELECT COUNT(*) FROM travel_groups WHERE status IN ('forming','full')",
    'Confirmed'     => "SELECT COUNT(*) FROM travel_groups WHERE status = 'confirmed'",
    'Completed'     => "SELECT COUNT(*) FROM travel_groups WHERE status = 'completed'",
    'Packages'      => 'SELECT COUNT(*) FROM packages WHERE is_active = 1',
    'Demo payments' => "SELECT COUNT(*) FROM bookings WHERE status = 'paid'",
    'Trip searches' => 'SELECT COUNT(*) FROM trip_intents',
] as $label => $sql) {
    $counts[$label] = (int) db()->query($sql)->fetchColumn();
}

$users = db()->query(
    "SELECT u.*, un.short_name,
            (SELECT COUNT(*) FROM group_members gm WHERE gm.user_id = u.id AND gm.status = 'joined') AS group_count
     FROM users u LEFT JOIN universities un ON un.id = u.university_id
     ORDER BY u.role = 'admin' DESC, u.created_at DESC"
)->fetchAll();

$groups = fetch_groups('1', [], 'g.created_at DESC', 8);

$pageTitle = 'Admin';
$activeNav = 'admin/index.php';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <div class="page-head"><h1>Admin Panel</h1><p>Manage catalogue data and student accounts.</p></div>
    <?= admin_nav('index.php') ?>
    <?= render_flash() ?>

    <div class="stat-grid stat-grid-4">
        <?php foreach ($counts as $label => $n): ?>
            <div class="stat"><span><?= e($label) ?></span><strong><?= $n ?></strong></div>
        <?php endforeach; ?>
    </div>

    <section class="card">
        <h2 class="card-section-title">Users</h2>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Name</th><th>Email</th><th>University</th><th>Groups</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?php if ($u['role'] === 'student' && $u['email_verified_at']): ?><a href="<?= url('profile.php?id=' . $u['id']) ?>"><?= e($u['full_name']) ?></a><?php else: ?><?= e($u['full_name']) ?><?php endif; ?></td>
                        <td><?= e($u['email']) ?></td>
                        <td><?= e($u['short_name'] ?? '–') ?></td>
                        <td><?= (int) $u['group_count'] ?></td>
                        <td>
                            <?php if ($u['role'] === 'admin'): ?><span class="badge badge-solid">Admin</span>
                            <?php elseif ($u['is_blocked']): ?><span class="badge badge-accent">Blocked</span>
                            <?php elseif (!$u['email_verified_at']): ?><span class="badge badge-grey">Not verified</span>
                            <?php else: ?><span class="badge">Active</span><?php endif; ?>
                        </td>
                        <td class="actions">
                            <?php if ($u['role'] === 'student'): ?>
                                <?php if (!$u['email_verified_at']): ?>
                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= $u['id'] ?>"><button class="link-button" name="action" value="verify">Verify</button></form>
                                <?php endif; ?>
                                <form method="post" data-confirm="<?= $u['is_blocked'] ? 'Unblock' : 'Block' ?> <?= e($u['full_name']) ?>?"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button class="link-button <?= $u['is_blocked'] ? '' : 'text-danger' ?>" name="action" value="<?= $u['is_blocked'] ? 'unblock' : 'block' ?>"><?= $u['is_blocked'] ? 'Unblock' : 'Block' ?></button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <h2 class="card-section-title">Latest Groups</h2>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Group</th><th>Package</th><th>Dates</th><th>Members</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($groups as $g): ?>
                    <tr>
                        <td><a href="<?= url('group.php?id=' . $g['id']) ?>"><?= e($g['title']) ?></a></td>
                        <td><?= e($g['package_title']) ?></td>
                        <td><?= e(date_range($g['start_date'], $g['end_date'])) ?></td>
                        <td><?= (int) $g['member_count'] ?>/<?= (int) $g['max_members'] ?></td>
                        <td><?= status_badge($g['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
