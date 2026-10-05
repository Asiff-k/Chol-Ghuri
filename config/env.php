<?php
/**
 * env.php
 * -------
 * Reads settings from config/.env so that secrets (like the SMTP password)
 * are NOT written inside the PHP source code.
 *
 * config/.env looks like:
 *     APP_URL=http://localhost/chol-ghuri
 *     SMTP_HOST=smtp.gmail.com
 *     SMTP_PASSWORD="my app password"
 *
 * A real environment variable with the same name (e.g. set by a hosting
 * provider) wins over the value in the file.
 * The config/ folder is blocked from the browser by config/.htaccess.
 */

function load_env_file(string $path): array
{
    $values = [];
    if (!is_file($path)) {
        return $values;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;                                   // comment or not a setting
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        // Remove surrounding quotes: "value" or 'value'
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        $values[$key] = $value;
    }
    return $values;
}

/** env('SMTP_HOST', 'default') */
function env(string $key, string $default = ''): string
{
    static $file = null;
    if ($file === null) {
        $file = load_env_file(__DIR__ . '/.env');
    }
    $real = getenv($key);
    if ($real !== false && $real !== '') {
        return $real;
    }
    return $file[$key] ?? $default;
}
