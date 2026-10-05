<?php
/**
 * admin.php
 * ---------
 * Small helpers shared by the pages in /admin.
 */

/** Sub-navigation shown at the top of every admin page. */
function admin_nav(string $active): string
{
    $links = [
        'index.php'        => ['Overview & Users', 'layout-dashboard'],
        'packages.php'     => ['Packages & Costs', 'hotel'],
        'destinations.php' => ['Destinations', 'map'],
        'universities.php' => ['Universities', 'university'],
        'tags.php'         => ['Tags', 'tag'],
    ];
    $html = '<nav class="admin-nav" aria-label="Admin">';
    foreach ($links as $file => [$label, $ic]) {
        $html .= '<a href="' . url('admin/' . $file) . '" class="' . ($file === $active ? 'active' : '') . '">' . icon($ic, 'icon-sm') . ' ' . e($label) . '</a>';
    }
    return $html . '</nav>';
}

/**
 * Save an uploaded JPG/PNG into uploads/images/ (for destinations & packages).
 * Returns [error message or '', new file name or null]. No file = ['', null].
 */
function save_uploaded_image(?array $file): array
{
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['', null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['The image could not be uploaded.', null];
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return ['The image must be 5 MB or smaller.', null];
    }
    $mime    = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($allowed[$mime]) || !getimagesize($file['tmp_name'])) {
        return ['Only JPG or PNG images are allowed.', null];
    }
    $dir = __DIR__ . '/../uploads/images/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $name = 'img_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    move_uploaded_file($file['tmp_name'], $dir . $name);
    return ['', $name];
}

/** Built-in photos the admin can pick from. */
function builtin_images(): array
{
    $files = glob(__DIR__ . '/../assets/images/destinations/*.jpg') ?: [];
    $files = array_merge($files, glob(__DIR__ . '/../uploads/images/*.{jpg,png}', GLOB_BRACE) ?: []);
    return array_map('basename', $files);
}
