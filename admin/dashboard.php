<?php
require_once __DIR__.'/../config.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$t = load_lang($_SESSION['lang'] ?? 'en');

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
<!doctype html><html><head>
<meta charset="utf-8"><title>Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/material.css">
<link rel="stylesheet" href="/assets/css/styles.css">
</head><body class="md-bg">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section grid">
  <div class="md-card md-elev-2"><h3>Users</h3><div class="md-kpi"><?=$users?></div></div>
  <div class="md-card md-elev-2"><h3>Questionnaires</h3><div class="md-kpi"><?=$q?></div></div>
  <div class="md-card md-elev-2"><h3>Responses</h3><div class="md-kpi"><?=$r?></div></div>
</section>
<section class="md-section">
  <a class="md-button md-primary md-elev-2" href="users.php">Manage Users</a>
  <a class="md-button md-elev-2" href="questionnaire_manage.php">Manage Questionnaires</a>
  <a class="md-button md-elev-2" href="supervisor_review.php">Review Queue</a>
  <a class="md-button md-elev-2" href="analytics.php">Analytics</a>
  <a class="md-button md-elev-2" href="export.php">Export CSV</a>
  <a class="md-button md-elev-2" href="branding.php">Branding & Landing</a>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body></html>