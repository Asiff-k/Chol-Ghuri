<?php
/**
 * verify.php - opened from the verification link: verify.php?token=abc123...
 *
 * We hash the token from the link and look for a user with that hash
 * whose link has not expired. If found, the account becomes verified
 * and the token is deleted so the link can't be used again.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$token = $_GET['token'] ?? '';
$verified = false;

if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $stmt = db()->prepare(
        'SELECT id FROM users WHERE verify_token_hash = ? AND verify_token_expires > ? AND email_verified_at IS NULL'
    );
    $stmt->execute([token_hash($token), date('Y-m-d H:i:s')]);
    $userId = $stmt->fetchColumn();

    if ($userId) {
        db()->prepare(
            'UPDATE users SET email_verified_at = ?, verify_token_hash = NULL, verify_token_expires = NULL WHERE id = ?'
        )->execute([date('Y-m-d H:i:s'), $userId]);

        unset($_SESSION['pending_user_id']);
        $verified = true;
    }
}

$pageTitle = $verified ? 'Email Verified' : 'Link Not Valid';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
    <section class="center-card narrow">
        <?php if ($verified): ?>
            <span class="icon-circle icon-circle-lg" style="background:var(--primary-tint)"><?= icon('circle-check') ?></span>
            <h1>Email verified!</h1>
            <p class="lead">Your email is confirmed and your Chol Ghuri account is now active.</p>
            <a href="<?= url('login.php') ?>" class="btn btn-primary btn-lg btn-block">Log In <?= icon('arrow-right', 'icon-sm') ?></a>
        <?php else: ?>
            <span class="icon-circle icon-circle-lg accent"><?= icon('circle-alert') ?></span>
            <h1>Link not valid</h1>
            <p class="lead">This verification link is invalid, has expired, or was already used. A newer link replaces older ones.</p>
            <a href="<?= url('login.php') ?>" class="btn btn-primary btn-lg btn-block">Log in to get a new link</a>
        <?php endif; ?>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
