<?php

require_once __DIR__ . '/../config.php';

const BRANDING_LOGO_MAX_DIMENSION = 480;
const BRANDING_LOGO_MAX_FILESIZE = 5_242_880; // 5 MB

/**
 * Ensure the upload directory for branding logos exists.
 */
function branding_logo_upload_dir(): string
{
    $uploadDirFs = BASE_PATH . '/assets/uploads/branding';
    if (!is_dir($uploadDirFs)) {
        if (!mkdir($uploadDirFs, 0775, true) && !is_dir($uploadDirFs)) {
            throw new RuntimeException('Unable to prepare upload directory.');
        }
    }

    if (!is_writable($uploadDirFs) && !@chmod($uploadDirFs, 0775)) {
        throw new RuntimeException('Upload directory is not writable.');
    }

    return $uploadDirFs;
}

/**
 * Delete a previously uploaded branding logo if it resides within the uploads directory.
 */
function branding_logo_delete_previous(?string $path): void
{
    if ($path === null || $path === '') {
        return;
    }

    $normalized = normalize_branding_logo_path($path);
    if ($normalized === null) {
        return;
    }

    if (!str_starts_with($normalized, '/assets/uploads/branding/')) {
        return;
    }

    $uploadDir = realpath(BASE_PATH . '/assets/uploads/branding');
    $candidate = realpath(BASE_PATH . $normalized);
    if ($uploadDir === false || $candidate === false) {
        return;
    }

    if (str_starts_with($candidate, $uploadDir) && is_file($candidate)) {
        @unlink($candidate);
    }
}

/**
 * Handle an uploaded branding logo and return the public path.
 *
 * @param array{error?:int,tmp_name?:string,size?:int,name?:string} $logoFile
 */
function branding_logo_store_upload(array $logoFile): string
{
    $errorCode = (int)($logoFile['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload error code: ' . $errorCode);
    }

    $tmp = $logoFile['tmp_name'] ?? '';
    if ($tmp === '' || !is_string($tmp) || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Upload origin could not be verified.');
    }

    if (($logoFile['size'] ?? 0) > BRANDING_LOGO_MAX_FILESIZE) {
        throw new RuntimeException('Uploaded logo is too large.');
    }

    if (!class_exists('finfo')) {
        throw new RuntimeException('PHP fileinfo extension required.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);

    $allowedBitmap = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
    ];

    if ($mime === 'image/svg+xml') {
        return branding_logo_store_svg($tmp);
    }

    if (!in_array($mime, $allowedBitmap, true)) {
        throw new RuntimeException('Unsupported logo type: ' . $mime);
    }

    return branding_logo_store_bitmap($tmp, $mime);
}

/**
 * Store an SVG upload by copying it directly into the upload directory.
 */
function branding_logo_store_svg(string $tmp): string
{
    $svg = file_get_contents($tmp);
    if ($svg === false) {
        throw new RuntimeException('Failed to read uploaded SVG logo.');
    }

    if (strlen($svg) > BRANDING_LOGO_MAX_FILESIZE) {
        throw new RuntimeException('Uploaded SVG logo is too large.');
    }

    $destination = branding_logo_upload_dir() . '/' . branding_logo_generate_filename('svg');
    if (file_put_contents($destination, $svg) === false) {
        throw new RuntimeException('Failed to store SVG logo.');
    }

    @chmod($destination, 0644);

    return '/assets/uploads/branding/' . basename($destination);
}

/**
 * Store a bitmap upload, normalising to PNG while respecting size limits.
 */
function branding_logo_store_bitmap(string $tmp, string $mime): string
{
    $info = @getimagesize($tmp);
    if ($info === false) {
        throw new RuntimeException('Unable to inspect uploaded logo.');
    }

    [$width, $height] = $info;
    if ($width <= 0 || $height <= 0) {
        throw new RuntimeException('Uploaded logo dimensions are invalid.');
    }

    $imageData = file_get_contents($tmp);
    if ($imageData === false) {
        throw new RuntimeException('Failed to read uploaded logo.');
    }

    $source = @imagecreatefromstring($imageData);
    if ($source === false) {
        throw new RuntimeException('Unable to process uploaded logo.');
    }

    $supportsAlpha = in_array($mime, ['image/png', 'image/gif', 'image/webp'], true);
    $scale = branding_logo_scale_factor($width, $height);
    if ($scale < 1) {
        $targetWidth = max(1, (int)round($width * $scale));
        $targetHeight = max(1, (int)round($height * $scale));
        if (function_exists('imagescale')) {
            $mode = defined('IMG_BICUBIC') ? IMG_BICUBIC : (defined('IMG_BILINEAR_FIXED') ? IMG_BILINEAR_FIXED : 0);
            $resampled = $mode !== 0 ? imagescale($source, $targetWidth, $targetHeight, $mode) : imagescale($source, $targetWidth, $targetHeight);
            if ($resampled === false) {
                imagedestroy($source);
                throw new RuntimeException('Failed to resize uploaded logo.');
            }
        } else {
            $resampled = branding_logo_prepare_canvas($targetWidth, $targetHeight, $supportsAlpha);
            if (!imagecopyresampled($resampled, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
                imagedestroy($source);
                imagedestroy($resampled);
                throw new RuntimeException('Failed to resize uploaded logo.');
            }
        }
        imagedestroy($source);
        $source = $resampled;
    }

    $final = branding_logo_prepare_canvas(imagesx($source), imagesy($source), $supportsAlpha);

    if (!imagecopy($final, $source, 0, 0, 0, 0, imagesx($source), imagesy($source))) {
        imagedestroy($source);
        imagedestroy($final);
        throw new RuntimeException('Failed to render uploaded logo.');
    }

    imagedestroy($source);

    $destination = branding_logo_upload_dir() . '/' . branding_logo_generate_filename('png');
    if (!imagepng($final, $destination, 6)) {
        imagedestroy($final);
        throw new RuntimeException('Failed to save processed logo.');
    }

    imagedestroy($final);
    @chmod($destination, 0644);

    return '/assets/uploads/branding/' . basename($destination);
}

/**
 * Create a prepared canvas for rendering with or without alpha support.
 */
function branding_logo_prepare_canvas(int $width, int $height, bool $supportsAlpha)
{
    $canvas = imagecreatetruecolor($width, $height);
    if ($canvas === false) {
        throw new RuntimeException('Failed to allocate image canvas.');
    }

    if ($supportsAlpha) {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);
    } else {
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
    }

    return $canvas;
}

/**
 * Calculate a scale factor that keeps the logo within the max dimensions.
 */
function branding_logo_scale_factor(int $width, int $height): float
{
    $scale = min(BRANDING_LOGO_MAX_DIMENSION / $width, BRANDING_LOGO_MAX_DIMENSION / $height, 1.0);
    return max($scale, 0.0);
}

/**
 * Generate a random filename for a branding logo.
 */
function branding_logo_generate_filename(string $extension): string
{
    return 'logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
}
