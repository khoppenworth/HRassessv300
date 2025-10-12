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
$statusRows = $pdo->query("SELECT status, COUNT(*) c FROM questionnaire_response GROUP BY status ORDER BY status ASC")->fetchAll();

$avgChartData = [];
foreach (array_slice($avg, 0, 12) as $row) {
  $label = trim((string)($row['full_name'] ?? ''));
  if ($label === '') {
    $label = trim((string)($row['username'] ?? 'User'));
  }
  if ($label === '') {
    $label = 'User';
  }
  $avgChartData[] = [
    'label' => $label,
    'average' => round((float)($row['avg_score'] ?? 0), 2),
    'count' => (int)($row['cnt'] ?? 0),
  ];
}

$timeSeries = [];
foreach ($time as $row) {
  $rawDate = $row['d'] ?? null;
  $label = $rawDate ? date('M j', strtotime($rawDate)) : '';
  $timeSeries[] = [
    'date' => $rawDate,
    'label' => $label,
    'count' => (int)($row['c'] ?? 0),
  ];
}

$workFunctionChart = [];
foreach ($workFunctionStats as $row) {
  $wfKey = $row['work_function'] ?? '';
  $wfLabel = WORK_FUNCTION_LABELS[$wfKey] ?? ($wfKey !== '' ? $wfKey : t($t, 'unknown', 'Unknown'));
  $workFunctionChart[] = [
    'label' => $wfLabel,
    'total' => (int)($row['total_responses'] ?? 0),
    'approved' => (int)($row['approved_count'] ?? 0),
    'average' => round((float)($row['avg_score'] ?? 0), 1),
  ];
}

$statusLabelMap = [
  'draft' => t($t, 'status_draft', 'Draft'),
  'submitted' => t($t, 'status_submitted', 'Submitted'),
  'approved' => t($t, 'status_approved', 'Approved'),
  'rejected' => t($t, 'status_rejected', 'Returned'),
];
$statusChart = [];
foreach ($statusRows as $row) {
  $key = (string)($row['status'] ?? '');
  $statusChart[] = [
    'label' => $statusLabelMap[$key] ?? ($key !== '' ? ucfirst($key) : t($t, 'unknown', 'Unknown')),
    'value' => (int)($row['c'] ?? 0),
  ];
}

$avgChartJson = json_encode($avgChartData, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$timeChartJson = json_encode($timeSeries, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$workFunctionJson = json_encode($workFunctionChart, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$statusChartJson = json_encode($statusChart, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
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
  <div class="md-dashboard-grid md-dashboard-grid--analytics">
    <div class="md-card md-elev-2">
      <h2 class="md-card-title"><?=t($t,'avg_score_chart','Average Score by User')?></h2>
      <?php if ($avgChartData): ?>
        <canvas id="avgScoreChart" height="280"></canvas>
        <p class="md-upgrade-meta"><?=t($t,'avg_score_chart_hint','Top performers are limited to the most recent 12 users with scored responses.')?></p>
      <?php else: ?>
        <p class="md-upgrade-meta"><?=t($t,'avg_score_chart_empty','Score data will appear here after responses are submitted.')?></p>
      <?php endif; ?>
    </div>
    <div class="md-card md-elev-2">
      <h2 class="md-card-title"><?=t($t,'submissions_trend','Submissions Trend')?></h2>
      <?php if ($timeSeries): ?>
        <canvas id="submissionTrendChart" height="280"></canvas>
        <p class="md-upgrade-meta"><?=t($t,'submissions_trend_hint','Monitor the pacing of questionnaire activity over time.')?></p>
      <?php else: ?>
        <p class="md-upgrade-meta"><?=t($t,'submissions_trend_empty','No submissions recorded yet for the selected period.')?></p>
      <?php endif; ?>
    </div>
    <div class="md-card md-elev-2">
      <h2 class="md-card-title"><?=t($t,'work_function_performance','Work Function Performance')?></h2>
      <?php if ($workFunctionChart): ?>
        <canvas id="workFunctionChart" height="280"></canvas>
        <p class="md-upgrade-meta"><?=t($t,'work_function_hint','Compare response volume, approvals and average scores by work function.')?></p>
      <?php else: ?>
        <p class="md-upgrade-meta"><?=t($t,'work_function_empty','Assign questionnaires to teams to see benchmarks populate here.')?></p>
      <?php endif; ?>
    </div>
    <div class="md-card md-elev-2">
      <h2 class="md-card-title"><?=t($t,'status_mix','Response Status Mix')?></h2>
      <?php if ($statusChart): ?>
        <canvas id="statusChart" height="280"></canvas>
        <p class="md-upgrade-meta"><?=t($t,'status_mix_hint','Quickly spot where responses are waiting for review or approval.')?></p>
      <?php else: ?>
        <p class="md-upgrade-meta"><?=t($t,'status_mix_empty','Once assessments are started the status mix will be visualised here.')?></p>
      <?php endif; ?>
    </div>
  </div>
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
  <script src="<?=asset_url('assets/adminlte/plugins/chart.js/Chart.min.js')?>"></script>
  <script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
    (function () {
      if (typeof window.Chart === 'undefined') {
        return;
      }
      const avgData = <?=$avgChartJson?>;
      const timeData = <?=$timeChartJson?>;
      const workFunctionData = <?=$workFunctionJson?>;
      const statusData = <?=$statusChartJson?>;
      const rootStyles = getComputedStyle(document.documentElement);
      const cssVar = (name, fallback) => {
        const value = rootStyles.getPropertyValue(name);
        return value ? value.trim() : fallback;
      };
      const palette = {
        primary: cssVar('--app-primary', '#2073bf'),
        secondary: cssVar('--app-secondary', '#61b3ec'),
        accent: cssVar('--app-accent', '#f6b511'),
        muted: cssVar('--app-muted', '#2b4160'),
        border: cssVar('--app-primary-dark', '#165997'),
      };

      const avgCanvas = document.getElementById('avgScoreChart');
      if (avgCanvas && avgData.length) {
        new Chart(avgCanvas, {
          type: 'bar',
          data: {
            labels: avgData.map((entry) => entry.label),
            datasets: [
              {
                label: 'Average %',
                data: avgData.map((entry) => entry.average),
                backgroundColor: palette.primary,
                borderColor: palette.border,
                borderWidth: 1,
                borderRadius: 6,
                maxBarThickness: 42,
              },
              {
                type: 'line',
                label: 'Responses',
                data: avgData.map((entry) => entry.count),
                borderColor: palette.accent,
                backgroundColor: palette.accent,
                yAxisID: 'y1',
                tension: 0.28,
                fill: false,
                pointRadius: 3,
                pointHoverRadius: 5,
              },
            ],
          },
          options: {
            maintainAspectRatio: false,
            scales: {
              y: {
                beginAtZero: true,
                ticks: {
                  callback: (value) => `${value}%`,
                },
              },
              y1: {
                beginAtZero: true,
                position: 'right',
                grid: {
                  drawOnChartArea: false,
                },
              },
            },
          },
        });
      }

      const trendCanvas = document.getElementById('submissionTrendChart');
      if (trendCanvas && timeData.length) {
        new Chart(trendCanvas, {
          type: 'line',
          data: {
            labels: timeData.map((entry) => entry.label || entry.date),
            datasets: [
              {
                label: 'Submissions',
                data: timeData.map((entry) => entry.count),
                borderColor: palette.secondary,
                backgroundColor: palette.secondary,
                fill: true,
                tension: 0.25,
                pointRadius: 3,
                pointHoverRadius: 5,
              },
            ],
          },
          options: {
            maintainAspectRatio: false,
            scales: {
              y: {
                beginAtZero: true,
                ticks: {
                  precision: 0,
                },
              },
            },
          },
        });
      }

      const wfCanvas = document.getElementById('workFunctionChart');
      if (wfCanvas && workFunctionData.length) {
        new Chart(wfCanvas, {
          data: {
            labels: workFunctionData.map((entry) => entry.label),
            datasets: [
              {
                type: 'bar',
                label: 'Responses',
                data: workFunctionData.map((entry) => entry.total),
                backgroundColor: palette.primary,
                borderRadius: 6,
                maxBarThickness: 48,
              },
              {
                type: 'bar',
                label: 'Approved',
                data: workFunctionData.map((entry) => entry.approved),
                backgroundColor: palette.secondary,
                borderRadius: 6,
                maxBarThickness: 48,
              },
              {
                type: 'line',
                label: 'Average %',
                data: workFunctionData.map((entry) => entry.average),
                borderColor: palette.accent,
                backgroundColor: palette.accent,
                yAxisID: 'y1',
                tension: 0.25,
                fill: false,
                pointRadius: 3,
                pointHoverRadius: 5,
              },
            ],
          },
          options: {
            maintainAspectRatio: false,
            scales: {
              y: {
                beginAtZero: true,
                stacked: false,
                ticks: { precision: 0 },
              },
              y1: {
                beginAtZero: true,
                position: 'right',
                grid: { drawOnChartArea: false },
              },
            },
          },
        });
      }

      const statusCanvas = document.getElementById('statusChart');
      if (statusCanvas && statusData.length) {
        const baseColors = [palette.primary, palette.secondary, palette.accent, palette.muted];
        const colors = statusData.map((_, index) => baseColors[index % baseColors.length]);
        new Chart(statusCanvas, {
          type: 'doughnut',
          data: {
            labels: statusData.map((entry) => entry.label),
            datasets: [
              {
                data: statusData.map((entry) => entry.value),
                backgroundColor: colors,
                borderColor: '#ffffff',
                borderWidth: 2,
              },
            ],
          },
          options: {
            maintainAspectRatio: false,
            plugins: {
              legend: {
                position: 'bottom',
              },
            },
          },
        });
      }
    })();
  </script>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
