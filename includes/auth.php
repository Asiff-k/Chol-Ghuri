<?php
/**
 * auth.php
 * --------
 * Everything about "who is logged in":
 * sessions, login, logout, "remember me" and page protection.
 *
 * How login works:
 *   - After a correct password we store the user's id in $_SESSION['user_id'].
 *   - On every page current_user() reads that id and loads the user from the database.
 *   - require_login() at the top of a page sends guests to the login page.
 */

// ---- Start the session (PHP remembers the user between pages with a cookie) ----
if (session_status() === PHP_SESSION_NONE) {
    session_name('cholghuri_session');
    session_set_cookie_params([
        'lifetime' => 0,               // until the browser closes
        'path'     => BASE_URL . '/',
        'httponly' => true,            // JavaScript can't read the cookie
        'samesite' => 'Lax',           // basic protection against cross-site requests
    ]);
    session_start();
}

const REMEMBER_COOKIE = 'cholghuri_remember';

/**
 * The logged-in user (with university info), or null for guests.
 * The result is cached so the database is asked only once per page.
 */
function current_user(): ?array
{
    static $user = false;

    if ($user === false) {
        $user = null;

        if (empty($_SESSION['user_id'])) {
            try_remember_me_login();
        }

        if (!empty($_SESSION['user_id'])) {
            $user = find_user_by_id((int) $_SESSION['user_id']);

            // Account deleted or blocked since they logged in -> log them out
            if (!$user || $user['is_blocked']) {
                logout_user();
                $user = null;
            }
        }
    }

    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'admin';
}

/** Put at the top of pages only logged-in users may see. */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        // Remember where they wanted to go, so we can send them back after login
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        set_flash('info', 'Please log in to continue.');
        redirect('login.php');
    }
    return $user;
}

/** Put at the top of login/register: logged-in users don't need them. */
function require_guest(): void
{
    if (is_logged_in()) {
        redirect(current_user()['role'] === 'admin' ? 'admin/index.php' : 'dashboard.php');
    }
}

/** Put at the top of admin pages. */
function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        show_error_page('Admins only', 'This area is only for Chol Ghuri administrators.', 403, 'dashboard.php', 'Go to dashboard');
    }
    return $user;
}

/**
 * Log a user in (call only after the password has been checked).
 */
function login_user(array $user, bool $remember = false): void
{
    // New session id after login stops "session fixation" attacks
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];

    db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')
        ->execute([date('Y-m-d H:i:s'), $user['id']]);

    if ($remember) {
        issue_remember_cookie((int) $user['id']);
    }
}

/** Log out: forget the session and the remember-me cookie. */
function logout_user(): void
{
    if (!empty($_SESSION['user_id'])) {
        db()->prepare('UPDATE users SET remember_token_hash = NULL, remember_token_expires = NULL WHERE id = ?')
            ->execute([$_SESSION['user_id']]);
    }
    setcookie(REMEMBER_COOKIE, '', time() - 3600, BASE_URL . '/');

    $_SESSION = [];
    session_regenerate_id(true);
}

// ---------------------------------------------------------------------
// "Remember me"
// The cookie holds "userId:randomToken". The database holds only a hash of
// the token, so a stolen database can't be turned into a login cookie.
// ---------------------------------------------------------------------

function issue_remember_cookie(int $userId): void
{
    $token   = bin2hex(random_bytes(32));
    $expires = time() + REMEMBER_ME_DAYS * 86400;

    db()->prepare('UPDATE users SET remember_token_hash = ?, remember_token_expires = ? WHERE id = ?')
        ->execute([hash('sha256', $token), date('Y-m-d H:i:s', $expires), $userId]);

    setcookie(REMEMBER_COOKIE, $userId . ':' . $token, [
        'expires'  => $expires,
        'path'     => BASE_URL . '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function try_remember_me_login(): void
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (!is_string($cookie) || !preg_match('/^(\d+):([a-f0-9]{64})$/', $cookie, $m)) {
        return;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$m[1]]);
    $user = $stmt->fetch();

    $valid = $user
        && $user['remember_token_hash']
        && hash_equals($user['remember_token_hash'], hash('sha256', $m[2]))
        && strtotime($user['remember_token_expires']) > time()
        && $user['email_verified_at'] !== null
        && !$user['is_blocked'];

    if ($valid) {
        login_user($user, true);   // also gives them a fresh token
    } else {
        setcookie(REMEMBER_COOKIE, '', time() - 3600, BASE_URL . '/');
    }
}
