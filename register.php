<?php
/**
 * register.php - Student Registration (design: "Student Registration (Updated)")
 *
 * 1. Student fills in name, university, email and password.
 * 2. We check everything again here in PHP (never trust the browser).
 * 3. The university is saved with the account. Whether the email must belong to
 *    that university's domain depends on REQUIRE_UNIVERSITY_EMAIL in config.php
 *    (off during development, so personal emails like Gmail work).
 * 4. We create the account as NOT verified and make a verification link.
 * 5. We email the verification link (SMTP) and send them to verify-email.php.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_guest();

$universities = get_universities();
$errors = [];
$form = ['full_name' => '', 'university_id' => '', 'email' => ''];

if (is_post()) {
    verify_csrf();

    $form['full_name']     = trim($_POST['full_name'] ?? '');
    $form['university_id'] = (int) ($_POST['university_id'] ?? 0);
    $form['email']         = strtolower(trim($_POST['email'] ?? ''));
    $password              = $_POST['password'] ?? '';
    $confirm               = $_POST['confirm_password'] ?? '';

    // --- Full name ---
    if (mb_strlen($form['full_name']) < 2 || mb_strlen($form['full_name']) > 100) {
        $errors['full_name'] = 'Please enter your full name.';
    }

    // --- University ---
    $university = find_university($form['university_id']);
    if (!$university) {
        $errors['university_id'] = 'Please choose your university.';
    }

    // --- Email ---
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    } elseif ($university && !email_allowed_for_university($form['email'], $university)) {
        $errors['email'] = university_email_error($university);   // only when REQUIRE_UNIVERSITY_EMAIL is true
    } elseif (find_user_by_email($form['email'])) {
        $errors['email'] = 'An account with this email already exists. Try logging in.';
    }

    // --- Password ---
    if ($problem = password_problem($password)) {
        $errors['password'] = $problem;
    } elseif ($password !== $confirm) {
        $errors['confirm_password'] = 'The two passwords do not match.';
    }

    if (empty($_POST['terms'])) {
        $errors['terms'] = 'Please confirm that you agree to travel respectfully and share costs honestly.';
    }

    // --- Everything OK: create the account ---
    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();   // both inserts succeed, or neither does

        $pdo->prepare('INSERT INTO users (university_id, full_name, email, password_hash) VALUES (?, ?, ?, ?)')
            ->execute([$university['id'], $form['full_name'], $form['email'], password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO student_profiles (user_id) VALUES (?)')->execute([$userId]);
        $pdo->commit();

        // Send the verification email (real SMTP). The page that follows
        // tells the truth: "sent" only if the SMTP server accepted it.
        $_SESSION['pending_user_id'] = $userId;
        $mailError = send_verification_email(find_user_by_id($userId));
        if ($mailError) {
            set_flash('error', 'Your account was created, but the verification email could not be sent. ' . $mailError);
        } else {
            set_flash('success', 'Account created! We sent a verification link to ' . $form['email'] . '.');
        }
        redirect('verify-email.php');
    }
}

$pageTitle = 'Create Account';
$bodyClass = 'bg-white';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="auth-columns">

        <section class="auth-intro">
            <h1>Travel with students you can trust.</h1>
            <p>Create your Chol Ghuri account, verify your email, and start finding affordable group trips with fellow students.</p>
            <ul class="benefits">
                <li><span class="tick"><?= icon('check') ?></span> Email-verified community</li>
                <li><span class="tick"><?= icon('check') ?></span> Find compatible travel groups</li>
                <li><span class="tick"><?= icon('check') ?></span> Split travel costs fairly</li>
            </ul>
            <img src="<?= url('assets/images/students-hiking.jpg') ?>" alt="A group of students hiking together in the hills">
        </section>

        <section class="auth-card">
            <h1>Create your account</h1>
            <p class="lead">Join Chol Ghuri and start planning your next trip.</p>

            <?= render_flash() ?>

            <form method="post" novalidate>
                <?= csrf_field() ?>

                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input class="input" type="text" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>"
                           placeholder="e.g. Asif Khan" autocomplete="name" required>
                    <?= field_error($errors, 'full_name') ?>
                </div>

                <div class="form-group">
                    <label for="university_id">University</label>
                    <select class="input" id="university_id" name="university_id" required>
                        <option value="" data-domain="">Select your university</option>
                        <?php foreach ($universities as $uni): ?>
                            <option value="<?= $uni['id'] ?>" data-domain="<?= e($uni['email_domain']) ?>"
                                <?= (int) $form['university_id'] === (int) $uni['id'] ? 'selected' : '' ?>>
                                <?= e($uni['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= field_error($errors, 'university_id') ?>
                </div>

                <div class="form-group">
                    <label for="email"><?= REQUIRE_UNIVERSITY_EMAIL ? 'University Email' : 'Email' ?></label>
                    <input class="input <?= isset($errors['email']) ? 'is-invalid' : '' ?>" type="email" id="email" name="email"
                           value="<?= e($form['email']) ?>" placeholder="<?= REQUIRE_UNIVERSITY_EMAIL ? 'you@university.edu.bd' : 'you@example.com' ?>" autocomplete="email" required>
                    <?= field_error($errors, 'email') ?>
                    <?php if (REQUIRE_UNIVERSITY_EMAIL): ?>
                        <?php // The live domain check in main.js only runs when this element exists ?>
                        <div id="email-status" class="field-help" <?= isset($errors['email']) ? 'hidden' : '' ?>>Choose your university first.</div>
                        <div class="field-help">Use your official university email. We'll send a verification link to this address. Only recognised university email domains are eligible.</div>
                    <?php else: ?>
                        <div class="field-help">Use an email address you can access. We'll use it to verify your account.</div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <?= password_field('password', 'Create a strong password', true) ?>
                    <?= field_error($errors, 'password') ?>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <?= password_field('confirm_password', 'Re-enter your password') ?>
                    <?= field_error($errors, 'confirm_password') ?>
                </div>

                <div class="form-group">
                    <label class="check">
                        <input type="checkbox" name="terms" value="1" <?= !empty($_POST['terms']) ? 'checked' : '' ?>>
                        <span>I agree to travel respectfully and to share costs honestly with my group.</span>
                    </label>
                    <?= field_error($errors, 'terms') ?>
                </div>

                <button type="submit" class="btn btn-primary btn-lg btn-block">Create Account <?= icon('arrow-right', 'icon-sm') ?></button>
            </form>

            <p class="auth-switch">Already have an account? <a href="<?= url('login.php') ?>">Log in</a></p>
            <div class="auth-note">
                <?= icon('lock', 'icon-sm') ?> Your email is used to verify your account and send important account notifications.
            </div>
        </section>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
