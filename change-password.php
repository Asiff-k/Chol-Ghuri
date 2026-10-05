<?php
/**
 * change-password.php - change password while logged in
 * (linked from Edit Profile -> Account Actions -> Change Password)
 */
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_login();

$errors = [];

if (is_post()) {
    verify_csrf();
    $current  = $_POST['current_password'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $me['password_hash'])) {
        $errors['current_password'] = 'Your current password is not correct.';
    } elseif ($problem = password_problem($password)) {
        $errors['password'] = $problem;
    } elseif ($password !== $confirm) {
        $errors['confirm_password'] = 'The two passwords do not match.';
    }

    if (!$errors) {
        db()->prepare('UPDATE users SET password_hash = ?, remember_token_hash = NULL, remember_token_expires = NULL WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $me['id']]);
        set_flash('success', 'Your password has been changed.');
        redirect($me['role'] === 'admin' ? 'admin/index.php' : 'edit-profile.php');
    }
}

$pageTitle = 'Change Password';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <section class="center-card narrow">
        <span class="icon-circle icon-circle-lg"><?= icon('key-round') ?></span>
        <h1>Change password</h1>
        <p class="lead">Enter your current password, then choose a new one.</p>

        <form method="post" novalidate>
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="current_password">Current Password</label>
                <?= password_field('current_password', 'Your current password', false, true, 'current-password') ?>
                <?= field_error($errors, 'current_password') ?>
            </div>

            <div class="form-group">
                <label for="password">New Password</label>
                <?= password_field('password', 'Create a strong password', true) ?>
                <?= field_error($errors, 'password') ?>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>
                <?= password_field('confirm_password', 'Re-enter your new password') ?>
                <?= field_error($errors, 'confirm_password') ?>
            </div>

            <button type="submit" class="btn btn-primary btn-lg btn-block">Update Password</button>
        </form>

        <p style="margin:24px 0 0">
            <a href="<?= url($me['role'] === 'admin' ? 'admin/index.php' : 'edit-profile.php') ?>"><?= icon('arrow-left', 'icon-sm') ?> Back</a>
        </p>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
