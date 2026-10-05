<?php
/**
 * forgot-password.php - "Forgot your password?" (design: "Forgot Password")
 *
 * The student enters their account email. If an account exists we create a
 * one-time reset token (valid 60 minutes) and EMAIL the link via SMTP.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$email = '';
$error = '';
$sent  = false;

if (is_post()) {
    verify_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!smtp_configured()) {
        // Checked BEFORE looking up the account, so this message reveals nothing about who is registered.
        $error = smtp_missing_message();
    } else {
        $user = find_user_by_email($email);
        if ($user && !$user['is_blocked']) {
            $error = send_password_reset_email($user);
        }
        // Same answer whether or not the account exists, so this form can't be
        // used to find out which emails are registered.
        $sent = $error === '';
    }
}

$pageTitle = 'Forgot Password';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <section class="center-card narrow">
        <span class="icon-circle icon-circle-lg"><?= icon('history') ?></span>
        <h1>Forgot your password?</h1>

        <?php if ($sent): ?>
            <p class="lead">If an account exists for <strong><?= e($email) ?></strong>, we've emailed a secure reset link. It expires in <?= RESET_TOKEN_MINUTES ?> minutes.</p>
            <p class="text-small">Didn't get it? Check your spam folder, or <a href="<?= url('forgot-password.php') ?>">try again</a>.</p>
        <?php else: ?>
            <p class="lead">Enter your account email and we'll send you a secure link to reset your password.</p>

            <?php if ($error): ?><?= alert('error', $error) ?><?php endif; ?>

            <form method="post" novalidate>
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="email">Email</label>
                    <div class="input-wrap">
                        <?= icon('mail') ?>
                        <input class="input" type="email" id="email" name="email" value="<?= e($email) ?>"
                               placeholder="you@example.com" autocomplete="email" required autofocus>
                    </div>
                </div>

                <div class="note-box">
                    <?= icon('info') ?>
                    <div>
                        <strong>Important Note</strong>
                        Use the email address of your Chol Ghuri account. For your security, the reset link expires after <?= RESET_TOKEN_MINUTES ?> minutes and works only once.
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg btn-block">Send Reset Link <?= icon('arrow-right', 'icon-sm') ?></button>
            </form>
        <?php endif; ?>

        <p style="margin:24px 0 0"><a href="<?= url('login.php') ?>"><?= icon('arrow-left', 'icon-sm') ?> Back to Login</a></p>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
