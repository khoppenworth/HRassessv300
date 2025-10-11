<?php
require_once __DIR__ . '/../config.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

/**
 * Attempt to fetch a COUNT(*) value while gracefully handling missing tables.
 */
$fetchCount = static function (PDO $pdo, string $sql): int {
    try {
        $stmt = $pdo->query($sql);
        $row = $stmt ? $stmt->fetch() : null;
        return (int)($row['c'] ?? 0);
    } catch (PDOException $e) {
        error_log('Admin dashboard metric failed: ' . $e->getMessage());
        return 0;
    }
};

$users = $fetchCount($pdo, 'SELECT COUNT(*) c FROM users');
$q = $fetchCount($pdo, 'SELECT COUNT(*) c FROM questionnaire');
$r = $fetchCount($pdo, 'SELECT COUNT(*) c FROM questionnaire_response');
?>
<!doctype html><html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>"><head>
<meta charset="utf-8"><title><?=htmlspecialchars(t($t,'admin_dashboard','Admin Dashboard'), ENT_QUOTES, 'UTF-8')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<link rel="manifest" href="<?=asset_url('manifest.webmanifest')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head><body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section grid">
  <div class="md-card md-elev-2"><h3><?=t($t,'users_count','Users')?></h3><div class="md-kpi"><?=$users?></div></div>
  <div class="md-card md-elev-2"><h3><?=t($t,'questionnaires_count','Questionnaires')?></h3><div class="md-kpi"><?=$q?></div></div>
  <div class="md-card md-elev-2"><h3><?=t($t,'responses_count','Responses')?></h3><div class="md-kpi"><?=$r?></div></div>
</section>
<section class="md-section">
  <a class="md-button md-primary md-elev-2" href="<?=htmlspecialchars(url_for('admin/users.php'), ENT_QUOTES, 'UTF-8')?>"><?=t($t,'manage_users','Manage Users')?></a>
  <a class="md-button md-elev-2" href="<?=htmlspecialchars(url_for('admin/questionnaire_manage.php'), ENT_QUOTES, 'UTF-8')?>"><?=t($t,'manage_questionnaires','Manage Questionnaires')?></a>
  <a class="md-button md-elev-2" href="<?=htmlspecialchars(url_for('admin/supervisor_review.php'), ENT_QUOTES, 'UTF-8')?>"><?=t($t,'review_queue','Review Queue')?></a>
  <a class="md-button md-elev-2" href="<?=htmlspecialchars(url_for('admin/analytics.php'), ENT_QUOTES, 'UTF-8')?>"><?=t($t,'analytics','Analytics')?></a>
  <a class="md-button md-elev-2" href="<?=htmlspecialchars(url_for('admin/export.php'), ENT_QUOTES, 'UTF-8')?>"><?=t($t,'export_data','Export Data')?></a>
  <a class="md-button md-elev-2" href="<?=htmlspecialchars(url_for('admin/branding.php'), ENT_QUOTES, 'UTF-8')?>"><?=t($t,'branding','Branding & Landing')?></a>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body></html>