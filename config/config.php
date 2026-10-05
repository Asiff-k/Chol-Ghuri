<?php
/**
 * config.php
 * ----------
 * All project settings live here, in one place.
 * Secrets and machine-specific values (site address, SMTP login) are read
 * from config/.env, see config/.env.example.
 */

require_once __DIR__ . '/env.php';

// ---- Database settings (XAMPP defaults) ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'chol_ghuri');
define('DB_USER', 'root');
define('DB_PASS', '');            // XAMPP's root user has no password by default
define('DB_CHARSET', 'utf8mb4');  // utf8mb4 supports Bangla text and the ৳ sign

// ---- App settings ----
define('APP_NAME', 'Chol Ghuri');

// Full address of the site (from config/.env), e.g. http://localhost/chol-ghuri
// Links inside emails are built from this - never from the browser's Host header,
// so a fake Host header can't make us email a link to someone else's website.
$appUrl = rtrim(env('APP_URL', 'http://localhost/chol-ghuri'), '/');
if (!preg_match('#^https?://[^/\s]+(/[^\s]*)?$#', $appUrl)) {
    exit('APP_URL in config/.env must look like http://localhost/chol-ghuri');
}
define('APP_URL', $appUrl);

// The path part of APP_URL ('/chol-ghuri'), used for links, CSS, JS and cookies.
define('BASE_URL', rtrim((string) parse_url(APP_URL, PHP_URL_PATH), '/'));

// ---- Outgoing email (SMTP), all from config/.env ----
define('SMTP_HOST',       env('SMTP_HOST'));
define('SMTP_PORT',       (int) env('SMTP_PORT', '587'));
define('SMTP_ENCRYPTION', strtolower(env('SMTP_ENCRYPTION', 'tls')));   // tls | ssl | none
define('SMTP_USERNAME',   env('SMTP_USERNAME'));
define('SMTP_PASSWORD',   env('SMTP_PASSWORD'));
define('MAIL_FROM',       env('MAIL_FROM'));
define('MAIL_FROM_NAME',  env('MAIL_FROM_NAME', 'Chol Ghuri'));

// UNIVERSITY EMAIL RULE
//   false (development/demo): any email address can register, e.g. you@gmail.com.
//                              The student still chooses their university, which is saved.
//   true  (strict):            the email must belong to the chosen university's domain
//                              from the universities table, e.g. you@lus.ac.bd.
define('REQUIRE_UNIVERSITY_EMAIL', false);

// How long links stay valid
define('VERIFY_TOKEN_HOURS', 24);
define('RESET_TOKEN_MINUTES', 60);
define('RESEND_COOLDOWN_SECONDS', 60);   // "Resend available in 00:59"
define('REMEMBER_ME_DAYS', 30);

// Profile photo uploads
define('AVATAR_DIR', __DIR__ . '/../uploads/avatars/');
define('AVATAR_MAX_BYTES', 5 * 1024 * 1024);   // 5 MB

// DEBUG_MODE
//   true  -> PHP/database errors are shown on screen (useful while coding)
//   false -> visitors see a friendly "Something went wrong" page and the
//            real error is written to logs/error.log (protected from the browser)
define('DEBUG_MODE', strtolower(env('APP_DEBUG', 'false')) === 'true');

// ---- PHP settings ----
date_default_timezone_set('Asia/Dhaka');

error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/error.log');
ini_set('display_errors', DEBUG_MODE ? '1' : '0');

if (!DEBUG_MODE) {
    // Any uncaught error: log the details, show a friendly page.
    set_exception_handler(function (Throwable $ex) {
        error_log('[' . date('Y-m-d H:i:s') . '] ' . get_class($ex) . ': ' . $ex->getMessage()
            . ' in ' . $ex->getFile() . ':' . $ex->getLine());
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Something went wrong | Chol Ghuri</title></head>'
            . '<body style="font-family:Segoe UI,system-ui,sans-serif;background:#f7f9f9;color:#1b1c1c;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:16px">'
            . '<div style="background:#fff;border:1px solid #e4e8e6;border-radius:16px;padding:40px;max-width:440px;text-align:center">'
            . '<h1 style="color:#006a4e;margin-top:0">Something went wrong</h1>'
            . '<p>Sorry, we could not complete that request. Please go back and try again.</p>'
            . '<p style="color:#6f7973;font-size:14px">If MySQL is stopped in XAMPP, start it and refresh.</p>'
            . '<a href="' . BASE_URL . '/index.php" style="display:inline-block;background:#006a4e;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600">Go to home</a>'
            . '</div></body></html>';
        exit;
    });
}
