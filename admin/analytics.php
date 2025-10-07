<?php
require_once __DIR__.'/../config.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$t = load_lang($_SESSION['lang'] ?? 'en');

$avg = $pdo->query("SELECT u.username, AVG(score) avg_score, COUNT(*) cnt FROM questionnaire_response qr JOIN users u ON u.id=qr.user_id GROUP BY u.id ORDER BY avg_score DESC")->fetchAll();
$time = $pdo->query("SELECT DATE(created_at) d, COUNT(*) c FROM questionnaire_response GROUP BY DATE(created_at) ORDER BY d ASC")->fetchAll();
$looker_sql = "SELECT qr.id as response_id, u.username, u.role, qr.questionnaire_id, qr.status, qr.score, qr.created_at, qr.reviewed_at, (qr.status='approved') as approved_flag FROM questionnaire_response qr JOIN users u ON u.id=qr.user_id";
?>
<!doctype html><html><head><meta charset="utf-8"><title>Analytics</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/material.css">
<link rel="stylesheet" href="/assets/css/styles.css"></head>
<body class="md-bg">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
<div class="md-card md-elev-2">
  <h2 class="md-card-title">Average Score per User</h2>
  <table class="md-table"><thead><tr><th>User</th><th>Average Score (%)</th><th>N</th></tr></thead><tbody>
  <?php foreach ($avg as $r): ?><tr><td><?=htmlspecialchars($r['username'])?></td><td><?=number_format((float)$r['avg_score'],2)?></td><td><?=$r['cnt']?></td></tr><?php endforeach; ?>
  </tbody></table>
</div>
<div class="md-card md-elev-2">
  <h2 class="md-card-title">Submissions Over Time (daily)</h2>
  <table class="md-table"><thead><tr><th>Date</th><th>Count</th></tr></thead><tbody>
  <?php foreach ($time as $r): ?><tr><td><?=$r['d']?></td><td><?=$r['c']?></td></tr><?php endforeach; ?>
  </tbody></table>
</div>
<div class="md-card md-elev-2">
  <h2 class="md-card-title">Looker Studio Fields (SQL)</h2>
  <pre><?=htmlspecialchars($looker_sql)?></pre>
</div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body></html>