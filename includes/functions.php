<?php
/**
 * functions.php
 * -------------
 * Small helper functions used on many pages.
 */

// ---------------------------------------------------------------------
// Output & links
// ---------------------------------------------------------------------

/**
 * Escape text before printing it into HTML.
 * Always use this for data that came from users or the database:
 *     <?= e($user['full_name']) ?>
 * This prevents XSS (someone injecting <script> tags).
 */
function e(?string $text): string
{
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

/** url('login.php') -> /chol-ghuri/login.php */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/** Send the browser to another page and stop. */
function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/** True when the page was submitted with a form (POST). */
function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/** Money in Bangladeshi Taka: money(4500) -> ৳4,500   money(-175) -> -৳175 */
function money(float|int|string|null $amount): string
{
    $value = round((float) $amount);
    return ($value < 0 ? '-' : '') . '৳' . number_format(abs($value));
}

/** Read a whole number from $_GET / $_POST (0 if missing or not a number). */
function int_param(array $source, string $key): int
{
    return isset($source[$key]) && is_numeric($source[$key]) ? (int) $source[$key] : 0;
}

/** True if $date is a real date written as YYYY-MM-DD. */
function valid_date(?string $date): bool
{
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    return checkdate($m, $d, $y);
}

/** Number of calendar days from start to end, both included. */
function days_between(string $start, string $end): int
{
    return (int) ((strtotime($end) - strtotime($start)) / 86400) + 1;
}

/** URL of a destination/package photo (admin uploads first, then the built-in photos). */
function image_url(?string $file): string
{
    $file = basename((string) $file);
    if ($file !== '' && is_file(__DIR__ . '/../uploads/images/' . $file)) {
        return url('uploads/images/' . rawurlencode($file));
    }
    if ($file !== '' && is_file(__DIR__ . '/../assets/images/destinations/' . $file)) {
        return url('assets/images/destinations/' . rawurlencode($file));
    }
    return url('assets/images/destinations/sajek.jpg');
}

const TRIP_TYPE_LABELS = [
    'day_trip'  => 'Day Trip',
    'overnight' => 'Overnight Stay',
    'eco_tour'  => 'Eco Tour',
    'any'       => 'Any type',
];

const CATEGORY_LABELS = [
    'transport'     => ['label' => 'Transport',     'icon' => 'bus'],
    'accommodation' => ['label' => 'Accommodation', 'icon' => 'bed-double'],
    'food'          => ['label' => 'Food',          'icon' => 'utensils'],
    'activities'    => ['label' => 'Activities',    'icon' => 'ticket'],
    'other'         => ['label' => 'Other',         'icon' => 'wallet'],
];

const AMENITY_LABELS = [
    'wifi'      => ['label' => 'Free Wi-Fi',          'icon' => 'wifi'],
    'breakfast' => ['label' => 'Breakfast included',  'icon' => 'coffee'],
    'ac'        => ['label' => 'Air conditioning',    'icon' => 'snowflake'],
    'beach'     => ['label' => 'Beach access',        'icon' => 'waves'],
    'pool'      => ['label' => 'Swimming pool',       'icon' => 'waves'],
    'gym'       => ['label' => 'Fitness centre',      'icon' => 'dumbbell'],
];

const PAYMENT_METHOD_LABELS = [
    'bkash' => 'bKash',
    'nagad' => 'Nagad',
    'card'  => 'Card',
    'cash'  => 'Cash',
];

/** Coloured pill for a group's status. */
function status_badge(string $status): string
{
    $map = [
        'forming'   => ['Forming', ''],
        'full'      => ['Full', 'badge-accent'],
        'confirmed' => ['Confirmed', 'badge-solid'],
        'completed' => ['Completed', 'badge-grey'],
        'cancelled' => ['Cancelled', 'badge-grey'],
    ];
    [$label, $class] = $map[$status] ?? [ucfirst($status), ''];
    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
}

/**
 * Show a friendly full-page message (404, 403...) and stop.
 * Used when a record doesn't exist or the user may not see it.
 */
function show_error_page(string $title, string $message, int $code = 404, string $linkUrl = 'index.php', string $linkText = 'Go to home'): never
{
    http_response_code($code);
    $pageTitle = $title;
    require __DIR__ . '/header.php';
    echo '<div class="container"><section class="center-card narrow">'
        . '<span class="icon-circle icon-circle-lg accent">' . icon('circle-alert') . '</span>'
        . '<h1>' . e($title) . '</h1><p class="lead">' . e($message) . '</p>'
        . '<a class="btn btn-primary" href="' . e(url($linkUrl)) . '">' . e($linkText) . '</a>'
        . '</section></div>';
    require __DIR__ . '/footer.php';
    exit;
}

/** Send a JSON answer (used by the small api/ endpoints) and stop. */
function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/** Five star icons, filled up to the (rounded) score. */
function stars(float $score): string
{
    $html = '<span class="stars" aria-label="' . e(number_format($score, 1)) . ' out of 5">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= icon('star', $i <= round($score) ? 'filled' : '');
    }
    return $html . '</span>';
}

/** "12-14 Dec 2026" style date range. */
function date_range(string $start, string $end): string
{
    $s = strtotime($start);
    $f = strtotime($end);
    if ($start === $end) {
        return date('j M Y', $s);
    }
    if (date('M Y', $s) === date('M Y', $f)) {
        return date('j', $s) . '–' . date('j M Y', $f);
    }
    return date('j M', $s) . ' – ' . date('j M Y', $f);
}

/**
 * Print an SVG icon from assets/icons/ (Lucide icons).
 *     <?= icon('mail') ?>   or   <?= icon('star', 'icon-sm text-accent') ?>
 */
function icon(string $name, string $class = ''): string
{
    static $cache = [];

    if (!isset($cache[$name])) {
        $file = __DIR__ . '/../assets/icons/' . basename($name) . '.svg';
        $svg  = is_file($file) ? file_get_contents($file) : '';
        $svg  = trim(preg_replace('/<!--.*?-->/s', '', $svg));            // remove licence comment
        // Remove width/height/class from the outer <svg ...> tag only (size comes from CSS).
        // Inner shapes like <rect width="20"> must keep theirs.
        $svg  = preg_replace_callback('/^<svg[^>]*>/', function ($m) {
            return preg_replace('/\s(width|height|class)="[^"]*"/', '', $m[0]);
        }, $svg);
        $cache[$name] = $svg;
    }

    $classes = trim('icon ' . $class);
    return str_replace('<svg', '<svg class="' . e($classes) . '" aria-hidden="true"', $cache[$name]);
}

/** "Asif Khan" -> "AK" (used when a student has no profile photo) */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

/**
 * Round profile picture. Shows the uploaded photo, or the initials if there is none.
 * $size: 'sm' (32px), 'md' (48px), 'lg' (96px), 'xl' (152px)
 */
function avatar(?string $photo, string $name, string $size = 'md'): string
{
    if ($photo && is_file(AVATAR_DIR . $photo)) {
        return '<img class="avatar avatar-' . e($size) . '" src="' . url('uploads/avatars/' . rawurlencode($photo)) . '" alt="' . e($name) . '">';
    }
    return '<span class="avatar avatar-' . e($size) . ' avatar-initials" aria-label="' . e($name) . '">' . e(initials($name)) . '</span>';
}

// ---------------------------------------------------------------------
// Flash messages (a message shown once on the next page)
// ---------------------------------------------------------------------

/** set_flash('success', 'Profile saved.') */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/** Print the flash message (if any) and delete it so it shows only once. */
function render_flash(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return alert($flash['type'], $flash['message']);
}

/** A coloured message box. $type: success | error | info | warning */
function alert(string $type, string $message): string
{
    $icons = ['success' => 'circle-check', 'error' => 'circle-alert', 'info' => 'info', 'warning' => 'shield-alert'];
    return '<div class="alert alert-' . e($type) . '" role="alert">'
        . icon($icons[$type] ?? 'info')
        . '<span>' . e($message) . '</span></div>';
}

// ---------------------------------------------------------------------
// Security: CSRF protection for forms
// A hidden random code is put in every form. If a POST arrives without the
// right code, it did not come from our own page, so we reject it.
// ---------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Put this inside every <form method="post">. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Call at the start of every POST handler. */
function verify_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Your session has expired. Please go back, refresh the page and try again.');
    }
}

// ---------------------------------------------------------------------
// Form helpers
// ---------------------------------------------------------------------

/** Red message under a field, if that field has an error. */
function field_error(array $errors, string $field): string
{
    if (empty($errors[$field])) {
        return '';
    }
    return '<div class="field-error">' . icon('circle-alert', 'icon-sm') . '<span>' . e($errors[$field]) . '</span></div>';
}

/**
 * A password box with the eye (show/hide) button.
 * $strength = true also shows the red/orange/green strength bar.
 */
function password_field(string $name, string $placeholder, bool $strength = false, bool $lockIcon = false, string $autocomplete = 'new-password'): string
{
    $id   = e($name);
    $html = '<div class="input-wrap has-end">';
    if ($lockIcon) {
        $html .= icon('lock');
    }
    $html .= '<input class="input" type="password" id="' . $id . '" name="' . $id . '" placeholder="' . e($placeholder) . '" autocomplete="' . e($autocomplete) . '" required>'
        . '<button type="button" class="input-end" data-toggle-password="' . $id . '" aria-label="Show password">'
        . '<span class="eye-open">' . icon('eye') . '</span>'
        . '<span class="eye-closed" style="display:none">' . icon('eye-off') . '</span>'
        . '</button></div>';

    if ($strength) {
        $html .= '<div class="strength" data-strength-for="' . $id . '" data-level="0"><span></span><span></span><span></span></div>'
            . '<div class="strength-text"></div>';
    }
    return $html;
}
