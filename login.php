<?php
/**
 * login.php - Student Login (design: "Student Login")
 *
 * 1. Find the user by email.
 * 2. password_verify() compares the typed password with the stored hash.
 * 3. Blocked accounts can't log in. Unverified accounts are sent to verify-email.php.
 * 4. On success we log them in (and set the "remember me" cookie if ticked).
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_guest();

$error = '';
$email = '';

if (is_post()) {
    verify_csrf();

    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $user     = find_user_by_email($email);

    // Same message whether the email or the password was wrong,
    // so nobody can use this form to find out which emails are registered.
    if (!$user || !password_verify($password, $user['password_hash'])) {
        $error = 'Incorrect email or password. Please try again.';
    } elseif ($user['is_blocked']) {
        $error = 'This account has been blocked by an administrator. Please contact support.';
    } elseif ($user['email_verified_at'] === null) {
        $_SESSION['pending_user_id'] = (int) $user['id'];
        set_flash('info', 'Please verify your email before logging in.');
        redirect('verify-email.php');
    } else {
        // Upgrade the hash automatically if PHP's default algorithm got stronger
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }

        login_user($user, !empty($_POST['remember']));

        // Go back to the page they wanted before logging in, if any
        $next = $_SESSION['redirect_after_login'] ?? '';
        unset($_SESSION['redirect_after_login']);
        if ($next && str_starts_with($next, BASE_URL . '/')) {
            header('Location: ' . $next);
            exit;
        }
        redirect($user['role'] === 'admin' ? 'admin/index.php' : 'dashboard.php');
    }
}

$pageTitle = 'Log In';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="auth-split">

        <section class="auth-photo" style="background-image:url('<?= url('assets/images/destinations/sundarbans.jpg') ?>')">
            <h2>Welcome back to Chol Ghuri.</h2>
            <p>Find your next adventure, connect with fellow students, and travel together for less.</p>
            <ul class="benefits">
                <li><span class="tick"><?= icon('check') ?></span> Email-verified community</li>
                <li><span class="tick"><?= icon('check') ?></span> Find compatible travel groups</li>
                <li><span class="tick"><?= icon('check') ?></span> Share travel costs fairly</li>
            </ul>
        </section>

        <section class="auth-form">
            <h1>Welcome back</h1>
            <p class="lead">Log in to continue your journey.</p>

            <?= render_flash() ?>
            <?php if ($error): ?>
                <?= alert('error', $error) ?>
            <?php endif; ?>

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

                <div class="form-group">
                    <label for="password">Password</label>
                    <?= password_field('password', 'Enter your password', false, true, 'current-password') ?>
                </div>

                <div class="form-inline-row">
                    <label class="check">
                        <input type="checkbox" name="remember" value="1"> <span>Remember me</span>
                    </label>
                    <a href="<?= url('forgot-password.php') ?>">Forgot password?</a>
                </div>

                <button type="submit" class="btn btn-primary btn-lg btn-block">Log In</button>
            </form>

            <p class="auth-switch">Don't have an account? <a href="<?= url('register.php') ?>">Create an account</a></p>

            <div class="auth-note">
                <?= icon('shield-check', 'icon-sm') ?> Only accounts with a verified email can log in.
                <small>Your account is protected by secure authentication.</small>
            </div>
        </section>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
