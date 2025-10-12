<?php
require_once __DIR__ . '/../config.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$avg = $pdo->query("SELECT u.username, u.full_name, AVG(score) avg_score, COUNT(*) cnt FROM questionnaire_response qr JOIN users u ON u.id=qr.user_id GROUP BY u.id ORDER BY avg_score DESC")->fetchAll();
$time = $pdo->query("SELECT DATE(created_at) d, COUNT(*) c FROM questionnaire_response GROUP BY DATE(created_at) ORDER BY d ASC")->fetchAll();
$workFunctionStats = $pdo->query("SELECT u.work_function, COUNT(*) total_responses, SUM(qr.status='approved') approved_count, AVG(qr.score) avg_score FROM questionnaire_response qr JOIN users u ON u.id = qr.user_id GROUP BY u.work_function ORDER BY avg_score DESC")->fetchAll();
$looker_sql = <<<SQL
SELECT
  qr.id AS response_id,
  u.username,
  u.full_name,
  u.email,
  u.role,
  u.work_function,
  u.account_status,
  qr.questionnaire_id,
  q.title AS questionnaire_title,
  qr.status,
  qr.score,
  qr.created_at,
  qr.reviewed_at,
  qr.review_comment,
  reviewer.username AS reviewer_username,
  reviewer.full_name AS reviewer_name,
  pp.label AS performance_period_label
FROM questionnaire_response qr
JOIN users u ON u.id = qr.user_id
LEFT JOIN questionnaire q ON q.id = qr.questionnaire_id
LEFT JOIN users reviewer ON reviewer.id = qr.reviewed_by
LEFT JOIN performance_period pp ON pp.id = qr.performance_period_id;
SQL;
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t,'analytics','Analytics'), ENT_QUOTES, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
  <link rel="manifest" href="<?=asset_url('manifest.webmanifest')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'avg_score_per_user','Average Score per User')?></h2>
    <table class="md-table">
      <thead>
        <tr>
          <th><?=t($t,'user','User')?></th>
          <th><?=t($t,'full_name','Full Name')?></th>
          <th><?=t($t,'average_score','Average Score (%)')?></th>
          <th><?=t($t,'count','Count')?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($avg as $r): ?>
          <?php $fullName = trim((string)($r['full_name'] ?? '')); ?>
          <tr>
            <td><?=htmlspecialchars($r['username'])?></td>
            <td><?= $fullName !== '' ? htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') : '—' ?></td>
            <td><?=number_format((float)$r['avg_score'], 2)?></td>
            <td><?=$r['cnt']?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'avg_score_per_work_function','Average Score per Work Function')?></h2>
    <table class="md-table">
      <thead>
        <tr>
          <th><?=t($t,'work_function','Work Function / Cadre')?></th>
          <th><?=t($t,'count','Responses')?></th>
          <th><?=t($t,'approved','Approved')?></th>
          <th><?=t($t,'average_score','Average Score (%)')?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($workFunctionStats as $row): ?>
          <?php
            $wfKey = $row['work_function'] ?? '';
            $wfLabel = WORK_FUNCTION_LABELS[$wfKey] ?? ($wfKey !== '' ? $wfKey : t($t,'unknown','Unknown'));
          ?>
          <tr>
            <td><?=htmlspecialchars($wfLabel, ENT_QUOTES, 'UTF-8')?></td>
            <td><?= (int)$row['total_responses'] ?></td>
            <td><?= (int)$row['approved_count'] ?></td>
            <td><?=number_format((float)$row['avg_score'], 2)?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'submissions_over_time','Submissions Over Time (daily)')?></h2>
    <table class="md-table">
      <thead>
        <tr><th><?=t($t,'date','Date')?></th><th><?=t($t,'count','Count')?></th></tr>
      </thead>
      <tbody>
        <?php foreach ($time as $r): ?>
          <tr>
            <td><?=$r['d']?></td>
            <td><?=$r['c']?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'looker_sql','Looker Studio Fields (SQL)')?></h2>
    <pre><?=htmlspecialchars($looker_sql)?></pre>
  </div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
