<?php
/**
 * reset-password.php - "Set a new password" (new screen, same design system)
 *
 * Opened from the reset link: reset-password.php?token=abc123...
 * If the token is valid and not expired, the student chooses a new password.
 * Afterwards the token is deleted (one-time use) and any "remember me"
 * logins are cancelled.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$token  = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$user   = find_user_by_reset_token($token);
$errors = [];

if ($user && is_post()) {
    verify_csrf();
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if ($problem = password_problem($password)) {
        $errors['password'] = $problem;
    } elseif ($password !== $confirm) {
        $errors['confirm_password'] = 'The two passwords do not match.';
    }

    if (!$errors) {
        // Opening a link sent to the email also proves the student owns that email,
        // so an unverified account becomes verified here as well.
        db()->prepare(
            'UPDATE users
             SET password_hash = ?, reset_token_hash = NULL, reset_token_expires = NULL,
                 remember_token_hash = NULL, remember_token_expires = NULL,
                 email_verified_at = COALESCE(email_verified_at, ?)
             WHERE id = ?'
        )->execute([password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s'), $user['id']]);

        if (is_logged_in()) {
            logout_user();
        }
        set_flash('success', 'Your password has been changed. Log in with your new password.');
        redirect('login.php');
    }
}

$pageTitle = 'Set New Password';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <section class="center-card narrow">
        <?php if (!$user): ?>
            <span class="icon-circle icon-circle-lg accent"><?= icon('circle-alert') ?></span>
            <h1>Link expired</h1>
            <p class="lead">This password reset link is invalid, has expired, or was already used.</p>
            <a href="<?= url('forgot-password.php') ?>" class="btn btn-primary btn-lg btn-block">Request a new link</a>
            <p style="margin:24px 0 0"><a href="<?= url('login.php') ?>"><?= icon('arrow-left', 'icon-sm') ?> Back to Login</a></p>
        <?php else: ?>
            <span class="icon-circle icon-circle-lg"><?= icon('lock-keyhole') ?></span>
            <h1>Set a new password</h1>
            <p class="lead">Choose a new password for <strong><?= e($user['email']) ?></strong>.</p>

            <form method="post" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">

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

                <div class="note-box">
                    <?= icon('info') ?>
                    <div>
                        <strong>Password tips</strong>
                        Use at least 8 characters with letters and numbers. After saving, you'll be logged out everywhere.
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg btn-block">Save New Password <?= icon('arrow-right', 'icon-sm') ?></button>
            </form>
        <?php endif; ?>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
