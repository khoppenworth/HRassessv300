<?php
function base_path(string $path = ''): string {
    return BASE_PATH . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function asset_url(string $path): string {
    $base = rtrim(BASE_URL, '/');
    return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
}

function url_for(string $path = ''): string {
    $trimmed = ltrim($path, '/');
    $base = rtrim(BASE_URL, '/');
    if ($trimmed === '') {
        return $base === '' ? '/' : $base . '/';
    }
    return ($base === '' ? '' : $base) . '/' . $trimmed;
}
?>
