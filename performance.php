<?php
require_once __DIR__.'/config.php';
auth_required(['staff','supervisor','admin']);
$t = load_lang($_SESSION['lang'] ?? 'en');

$stmt = $pdo->prepare("SELECT qr.*, q.title FROM questionnaire_response qr JOIN questionnaire q ON q.id=qr.questionnaire_id WHERE qr.user_id=? ORDER BY qr.created_at ASC");
$stmt->execute([$_SESSION['user']['id']]);
$rows = $stmt->fetchAll();
?>
<!doctype html><html><head>
<meta charset="utf-8"><title><?=t($t,'performance','Performance')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/css/material.css">
<link rel="stylesheet" href="assets/css/styles.css">
</head><body class="md-bg">
<?php include __DIR__.'/templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'your_trend','Your Score Trend')?></h2>
    <table class="md-table">
      <thead><tr><th><?=t($t,'date','Date')?></th><th><?=t($t,'questionnaire','Questionnaire')?></th><th><?=t($t,'score','Score (%)')?></th><th><?=t($t,'status','Status')?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?=htmlspecialchars($r['created_at'])?></td>
          <td><?=htmlspecialchars($r['title'])?></td>
          <td><?= is_null($r['score']) ? '-' : (int)$r['score']?></td>
          <td><?=htmlspecialchars($r['status'])?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php include __DIR__.'/templates/footer.php'; ?>
</body></html>