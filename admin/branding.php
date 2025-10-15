<?php
require_once __DIR__ . '/../config.php';

const BRANDING_LOGO_MAX_WIDTH = 480;
const BRANDING_LOGO_MAX_HEIGHT = 480;
const BRANDING_LOGO_MAX_FILESIZE = 5_242_880; // 5 MB

/**
 * @param resource $image
 */
function save_branding_image($image, string $destination, string $mime): void
{
    switch ($mime) {
        case 'image/png':
            if (!imagepng($image, $destination, 6)) {
                throw new RuntimeException('Failed to save PNG logo.');
            }
            break;
        case 'image/jpeg':
            if (!imagejpeg($image, $destination, 90)) {
                throw new RuntimeException('Failed to save JPEG logo.');
            }
            break;
        case 'image/gif':
            if (!imagegif($image, $destination)) {
                throw new RuntimeException('Failed to save GIF logo.');
            }
            break;
        case 'image/webp':
            if (!function_exists('imagewebp')) {
                throw new RuntimeException('WebP conversion is not available.');
            }
            if (!imagewebp($image, $destination, 90)) {
                throw new RuntimeException('Failed to save WebP logo.');
            }
            break;
        default:
            throw new RuntimeException('Unsupported image format.');
    }
}

/**
 * @return resource
 */
function create_branding_image(string $path, string $mime)
{
    return match ($mime) {
        'image/png' => imagecreatefrompng($path),
        'image/jpeg' => imagecreatefromjpeg($path),
        'image/gif' => imagecreatefromgif($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false,
        default => false,
    } ?: throw new RuntimeException('Unable to read uploaded logo.');
}

function ensure_branding_upload_dir(): string
{
    $uploadDirFs = BASE_PATH . '/assets/uploads/branding';
    if (!is_dir($uploadDirFs)) {
        if (!mkdir($uploadDirFs, 0775, true) && !is_dir($uploadDirFs)) {
            throw new RuntimeException('Failed to create upload directory.');
        }
    }

    return $uploadDirFs;
}

function delete_previous_branding_logo(?string $path): void
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

function handle_branding_logo_upload(array $logoFile): string
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

    $allowed = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/svg+xml' => 'svg',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Unsupported logo type: ' . $mime);
    }

    $uploadDirFs = ensure_branding_upload_dir();
    if (!is_writable($uploadDirFs) && !@chmod($uploadDirFs, 0775)) {
        throw new RuntimeException('Upload directory is not writable.');
    }

    $ext = $allowed[$mime];
    $name = 'logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $destFs = $uploadDirFs . '/' . $name;

    if ($mime === 'image/svg+xml') {
        $svg = file_get_contents($tmp);
        if ($svg === false) {
            throw new RuntimeException('Failed to read uploaded SVG logo.');
        }
        if (strlen($svg) > BRANDING_LOGO_MAX_FILESIZE) {
            throw new RuntimeException('Uploaded SVG logo is too large.');
        }
        if (file_put_contents($destFs, $svg) === false) {
            throw new RuntimeException('Failed to store SVG logo.');
        }
    } else {
        $info = @getimagesize($tmp);
        if ($info === false) {
            throw new RuntimeException('Unable to determine logo dimensions.');
        }

        [$width, $height] = $info;
        if ($width <= 0 || $height <= 0) {
            throw new RuntimeException('Uploaded logo dimensions are invalid.');
        }

        $scale = min(BRANDING_LOGO_MAX_WIDTH / $width, BRANDING_LOGO_MAX_HEIGHT / $height, 1.0);
        $targetWidth = max(1, (int)round($width * $scale));
        $targetHeight = max(1, (int)round($height * $scale));

        $source = create_branding_image($tmp, $mime);
        $resized = imagecreatetruecolor($targetWidth, $targetHeight);

        if (in_array($mime, ['image/png', 'image/gif', 'image/webp'], true)) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefill($resized, 0, 0, $transparent);
        }

        if (!imagecopyresampled($resized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
            imagedestroy($source);
            imagedestroy($resized);
            throw new RuntimeException('Failed to resize uploaded logo.');
        }

        save_branding_image($resized, $destFs, $mime);
        imagedestroy($source);
        imagedestroy($resized);
    }

    @chmod($destFs, 0644);

    return '/assets/uploads/branding/' . $name;
}

auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$msg = '';
$logoError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $site_name = trim($_POST['site_name'] ?? '');
    $landing_text = trim($_POST['landing_text'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $logo_path = get_branding_logo_path($cfg);
    $footer_org_name = trim($_POST['footer_org_name'] ?? '');
    $footer_org_short = trim($_POST['footer_org_short'] ?? '');
    $footer_website_label = trim($_POST['footer_website_label'] ?? '');
    $footer_website_url = trim($_POST['footer_website_url'] ?? '');
    $footer_email = trim($_POST['footer_email'] ?? '');
    $footer_phone = trim($_POST['footer_phone'] ?? '');
    $footer_hotline_label = trim($_POST['footer_hotline_label'] ?? '');
    $footer_hotline_number = trim($_POST['footer_hotline_number'] ?? '');
    $footer_rights = trim($_POST['footer_rights'] ?? '');
    if ($footer_website_url && !preg_match('#^https?://#i', $footer_website_url)) {
        $footer_website_url = 'https://' . ltrim($footer_website_url, '/');
    }

    $logoFile = $_FILES['branding_logo'] ?? null;
    $logoErrorCode = (int)($logoFile['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($logoErrorCode !== UPLOAD_ERR_NO_FILE) {
        try {
            $previousLogoPath = $logo_path;
            $logoWebPath = handle_branding_logo_upload($logoFile);
            persist_branding_logo_path($pdo, $logoWebPath);
            $logo_path = $logoWebPath;
            delete_previous_branding_logo($previousLogoPath);
        } catch (RuntimeException $e) {
            error_log('Logo upload failed: ' . $e->getMessage());
            if (str_starts_with($e->getMessage(), 'Unsupported')) {
                $logoError = t($t, 'invalid_file_type', 'Invalid file type. Logo was not updated.');
            } elseif (str_contains($e->getMessage(), 'large')) {
                $logoError = t($t, 'logo_too_large', 'Logo is too large. Upload a smaller image.');
            } else {
                $logoError = t($t, 'logo_upload_failed', 'Logo upload failed. Other changes were saved.');
            }
        }
    }

    $fields = [
        'site_name' => $site_name,
        'landing_text' => $landing_text,
        'address' => $address,
        'contact' => $contact,
        'logo_path' => $logo_path,
        'footer_org_name' => $footer_org_name,
        'footer_org_short' => $footer_org_short,
        'footer_website_label' => $footer_website_label,
        'footer_website_url' => $footer_website_url,
        'footer_email' => $footer_email,
        'footer_phone' => $footer_phone,
        'footer_hotline_label' => $footer_hotline_label,
        'footer_hotline_number' => $footer_hotline_number,
        'footer_rights' => $footer_rights,
    ];

    $assignments = [];
    $values = [];
    foreach ($fields as $column => $value) {
        $assignments[] = "$column=?";
        $values[] = ($value !== '') ? $value : null;
    }

    $stm = $pdo->prepare('UPDATE site_config SET ' . implode(', ', $assignments) . ' WHERE id=1');
    $stm->execute($values);
    if ($logoError !== null) {
        $msg = $logoError;
    } else {
        $msg = t($t, 'branding_updated', 'Branding updated successfully.');
    }
    $cfg = get_site_config($pdo);
}
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t,'branding','Branding & Landing'), ENT_QUOTES, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
  <link rel="manifest" href="<?=asset_url('manifest.php')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'branding','Branding & Landing')?></h2>
    <?php if ($msg): ?><div class="md-alert"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="<?=htmlspecialchars(url_for('admin/branding.php'), ENT_QUOTES, 'UTF-8')?>">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <label class="md-field"><span><?=t($t,'site_name','Site Name')?></span><input name="site_name" value="<?=htmlspecialchars($cfg['site_name'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'landing_text','Landing Text')?></span><textarea name="landing_text" rows="3"><?=htmlspecialchars($cfg['landing_text'] ?? '')?></textarea></label>
      <label class="md-field"><span><?=t($t,'address_label','Address')?></span><input name="address" value="<?=htmlspecialchars($cfg['address'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'contact_label','Contact')?></span><input name="contact" value="<?=htmlspecialchars($cfg['contact'] ?? '')?>"></label>
      <h3 class="md-subhead"><?=t($t,'footer_settings','Footer Details')?></h3>
      <label class="md-field"><span><?=t($t,'footer_org_name_label','Organization Name')?></span><input name="footer_org_name" value="<?=htmlspecialchars($cfg['footer_org_name'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'footer_org_short_label','Organization Short Name')?></span><input name="footer_org_short" value="<?=htmlspecialchars($cfg['footer_org_short'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'footer_website_label_label','Website Label')?></span><input name="footer_website_label" value="<?=htmlspecialchars($cfg['footer_website_label'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'footer_website_url_label','Website URL')?></span><input name="footer_website_url" type="url" value="<?=htmlspecialchars($cfg['footer_website_url'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'footer_email_label','Contact Email')?></span><input name="footer_email" type="email" value="<?=htmlspecialchars($cfg['footer_email'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'footer_phone_label','Phone Number')?></span><input name="footer_phone" value="<?=htmlspecialchars($cfg['footer_phone'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'footer_hotline_label_label','Hotline Label')?></span><input name="footer_hotline_label" value="<?=htmlspecialchars($cfg['footer_hotline_label'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'footer_hotline_number_label','Hotline Number')?></span><input name="footer_hotline_number" value="<?=htmlspecialchars($cfg['footer_hotline_number'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'footer_rights_label','Rights Statement')?></span><input name="footer_rights" value="<?=htmlspecialchars($cfg['footer_rights'] ?? '')?>"></label>
      <div class="md-field">
        <span><?=t($t,'logo','Logo')?></span>
        <input type="file" name="branding_logo" accept="image/*">
        <p class="md-hint"><?=t($t,'logo_hint','PNG, JPG, GIF, WebP, or SVG up to 5 MB. Larger images are resized to fit 480×480 px.')?></p>
        <?php $currentLogoPath = get_branding_logo_path($cfg);
        if (!empty($currentLogoPath)):
            $logoSrc = preg_match('#^https?://#i', $currentLogoPath) ? $currentLogoPath : asset_url(ltrim($currentLogoPath, '/'));
        ?>
          <div class="md-thumb"><img src="<?=htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8')?>" alt="Logo" height="40"></div>
        <?php endif; ?>
      </div>
      <button class="md-button md-primary md-elev-2"><?=t($t,'save','Save Changes')?></button>
    </form>
  </div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
