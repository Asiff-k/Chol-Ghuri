<?php
/**
 * footer.php
 * ----------
 * Bottom of every page: the ONE footer, then the JavaScript file.
 *
 * Usage at the very end of a page:
 *     require_once __DIR__ . '/includes/footer.php';
 */
?>
</main>

<footer class="site-footer">
    <div class="container footer-grid">
        <div class="footer-brand">
            <a href="<?= url('index.php') ?>" class="logo logo-sm"><?= e(APP_NAME) ?></a>
            <p>Helping university students explore Bangladesh together. Safe, affordable and fair.</p>
        </div>
        <div>
            <h4>Explore</h4>
            <a href="<?= url('packages.php') ?>">Packages</a>
            <a href="<?= url('destinations.php') ?>">Destinations</a>
            <a href="<?= url('groups.php') ?>">Find Groups</a>
        </div>
        <div>
            <h4>Your account</h4>
            <?php if (is_logged_in()): ?>
                <a href="<?= url('dashboard.php') ?>">Dashboard</a>
                <a href="<?= url('my-trips.php') ?>">My Trips</a>
            <?php else: ?>
                <a href="<?= url('register.php') ?>">Create account</a>
                <a href="<?= url('login.php') ?>">Log in</a>
            <?php endif; ?>
            <a href="<?= url('forgot-password.php') ?>">Reset password</a>
        </div>
        <div>
            <h4>About</h4>
            <a href="<?= url('index.php#how-it-works') ?>">How it works</a>
            <a href="<?= url('index.php#trust') ?>">Safety &amp; trust</a>
        </div>
    </div>
    <div class="container footer-bottom">
        &copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. For Students, By Students. Final-year project &middot; payments are demo only.
    </div>
</footer>

<script src="<?= url('assets/js/main.js') ?>"></script>
</body>
</html>
