<?php
declare(strict_types=1);

/**
 * Determine if a custom logo has been configured.
 */
function site_logo_is_custom(array $cfg): bool
{
    return trim((string)($cfg['logo_path'] ?? '')) !== '';
}

/**
 * Build the public URL for the site logo, falling back to the default asset.
 */
function site_logo_url(array $cfg): string
{
    $logoPath = trim((string)($cfg['logo_path'] ?? ''));
    if ($logoPath === '') {
        return asset_url('assets/img/epss-logo.svg');
    }

    if (preg_match('#^https?://#i', $logoPath)) {
        return $logoPath;
    }

    return asset_url(ltrim($logoPath, '/'));
}

/**
 * Resolve the absolute filesystem path for a custom logo stored under assets/uploads.
 */
function site_logo_file_path(array $cfg): ?string
{
    $logoPath = trim((string)($cfg['logo_path'] ?? ''));
    if ($logoPath === '' || preg_match('#^https?://#i', $logoPath)) {
        return null;
    }

    $relative = ltrim($logoPath, '/');
    if ($relative === '') {
        return null;
    }

    $absolute = base_path($relative);
    $uploadsRoot = rtrim(base_path('assets/uploads'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    $candidate = $absolute;
    if (is_file($absolute)) {
        $real = realpath($absolute);
        if ($real !== false) {
            $candidate = $real;
        }
    }

    $normalizedCandidate = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate), DIRECTORY_SEPARATOR);
    $normalizedRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $uploadsRoot), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    if (strpos($normalizedCandidate . DIRECTORY_SEPARATOR, $normalizedRoot) === 0) {
        return $candidate;
    }

    return null;
}
