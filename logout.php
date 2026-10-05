<?php
/**
 * logout.php - logs the user out.
 * Only accepts POST (the "Log Out" button in the menu) so another website
 * can't log people out just by linking here.
 */
require_once __DIR__ . '/includes/bootstrap.php';

if (is_post()) {
    verify_csrf();
    logout_user();
    set_flash('success', 'You have been logged out.');
    redirect('login.php');
}

redirect('index.php');
