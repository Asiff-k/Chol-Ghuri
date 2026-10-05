<?php
/**
 * verify-email.php - "Check your email" (design: "Verify Your University Email")
 *
 * Shown after registration, or when an unverified student tries to log in.
 * It knows WHO is waiting from $_SESSION['pending_user_id'].
 *
 * Actions on this page:
 *   - Resend:        email a fresh link (only once every 60 seconds; old links stop working)
 *   - Change email:  fix a typo in the address and email a new link
 *
 * The verification link itself is only ever sent by email, never shown here.
 */
require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$userId = (int) ($_SESSION['pending_user_id'] ?? 0);
$user   = $userId ? find_user_by_id($userId) : null;

if (!$user) {
    set_flash('info', 'Log in with your email to continue.');
    redirect('login.php');
}

if ($user['email_verified_at'] !== null) {
    unset($_SESSION['pending_user_id']);
    set_flash('success', 'Your email is already verified. Please log in.');
    redirect('login.php');
}

$errors = [];

if (is_post()) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'resend') {
        if (resend_wait_seconds($user['verify_sent_at']) > 0) {
            set_flash('error', 'Please wait a moment before requesting another email.');
        } elseif ($mailError = send_verification_email($user)) {
            set_flash('error', 'The verification email could not be sent. ' . $mailError);
        } else {
            set_flash('success', 'A new verification email was sent to ' . $user['email'] . '. Links in older emails no longer work.');
        }
        redirect('verify-email.php');
    }

    if ($action === 'change_email') {
        $newEmail = strtolower(trim($_POST['new_email'] ?? ''));
        $university = find_university((int) $user['university_id']);

        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $errors['new_email'] = 'Please enter a valid email address.';
        } elseif ($university && !email_allowed_for_university($newEmail, $university)) {
            $errors['new_email'] = university_email_error($university);   // only when REQUIRE_UNIVERSITY_EMAIL is true
        } elseif ($newEmail !== $user['email'] && find_user_by_email($newEmail)) {
            $errors['new_email'] = 'Another account already uses this email.';
        }

        if (!$errors) {
            db()->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$newEmail, $userId]);
            $mailError = send_verification_email(find_user_by_id($userId));
            set_flash($mailError ? 'error' : 'success', $mailError
                ? 'Email address updated, but the verification email could not be sent. ' . $mailError
                : 'Email address updated. We sent a new verification link to ' . $newEmail . '.');
            redirect('verify-email.php');
        }
    }

    $user = find_user_by_id($userId);   // reload after changes
}

$waitSeconds = resend_wait_seconds($user['verify_sent_at']);
$linkActive  = $user['verify_token_hash'] && strtotime((string) $user['verify_token_expires']) > time();

$pageTitle = 'Verify Your Email';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <section class="center-card">
        <?php if ($linkActive): ?>
            <span class="badge"><?= icon('circle-check', 'icon-sm') ?> Verification email sent</span>
        <?php else: ?>
            <span class="badge badge-accent"><?= icon('clock', 'icon-sm') ?> Verification required</span>
        <?php endif; ?>

        <div style="margin-top:24px"><span class="icon-circle icon-circle-lg"><?= icon('mail-check') ?></span></div>

        <h1>Check your email</h1>
        <p class="lead"><?= $linkActive
            ? 'We\'ve sent a verification link to your email address.'
            : 'Your email address needs to be verified before you can log in.' ?></p>
        <div class="email-chip"><?= e($user['email']) ?></div>

        <?= render_flash() ?>

        <?php if ($linkActive): ?>
            <p>Click the button in the email to activate your Chol Ghuri account. The link expires in <?= VERIFY_TOKEN_HOURS ?> hours. Check your spam folder if you can't find it.</p>
        <?php else: ?>
            <p>Press <strong>Resend verification email</strong> to get a new link.</p>
        <?php endif; ?>

        <div class="alert alert-warning">
            <?= icon('shield-alert') ?>
            <span>Your account will remain inactive until your email is verified.</span>
        </div>

        <form method="post" class="text-center">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="resend">
            <button type="submit" id="resend-button" class="btn btn-primary" <?= $waitSeconds > 0 ? 'disabled' : '' ?>><?= icon('send', 'icon-sm') ?> Resend verification email</button>
            <?php if ($waitSeconds > 0): ?>
                <p class="text-primary text-small" data-countdown="<?= $waitSeconds ?>" style="margin-top:8px"></p>
            <?php endif; ?>
        </form>

        <hr class="divider">

        <details class="change-email" <?= $errors ? 'open' : '' ?>>
            <summary>Typo in your email? Change email address</summary>
            <form method="post" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_email">
                <div class="form-group">
                    <label for="new_email">New email address</label>
                    <div class="input-wrap">
                        <?= icon('mail') ?>
                        <input class="input" type="email" id="new_email" name="new_email" value="<?= e($_POST['new_email'] ?? '') ?>" placeholder="you@example.com" required>
                    </div>
                    <?= field_error($errors, 'new_email') ?>
                </div>
                <button type="submit" class="btn btn-outline btn-sm">Update email &amp; send new link</button>
            </form>
        </details>

        <p style="margin-top:24px">Already verified? <a href="<?= url('login.php') ?>">Log in</a></p>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
