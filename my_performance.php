<?php
require_once __DIR__ . '/config.php';
auth_required(['staff','supervisor','admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$user = current_user();

$stmt = $pdo->prepare("SELECT qr.*, q.title, pp.label AS period_label FROM questionnaire_response qr JOIN questionnaire q ON q.id=qr.questionnaire_id JOIN performance_period pp ON pp.id = qr.performance_period_id WHERE qr.user_id=? ORDER BY qr.created_at ASC");
$stmt->execute([$user['id']]);
$rows = $stmt->fetchAll();
$draftResponses = array_values(array_filter($rows, static fn($row) => ($row['status'] ?? '') === 'draft'));
$nextAssessmentRaw = $user['next_assessment_date'] ?? null;
$nextAssessmentDisplay = null;
if ($nextAssessmentRaw) {
    $dt = DateTime::createFromFormat('Y-m-d', (string)$nextAssessmentRaw);
    if ($dt instanceof DateTime) {
        $nextAssessmentDisplay = $dt->format('F j, Y');
    } else {
        $nextAssessmentDisplay = $nextAssessmentRaw;
    }
}

$latestScores = [];
$latestEntry = null;
foreach ($rows as $row) {
    $latestScores[$row['questionnaire_id']] = $row;
    if ($latestEntry === null || strtotime($row['created_at']) > strtotime($latestEntry['created_at'])) {
        $latestEntry = $row;
    }
}

$belowThreshold = array_values(array_filter($rows, static function ($row) {
    return isset($row['score']) && $row['score'] !== null && (int)$row['score'] < 100;
}));

$chartLabels = [];
$chartScores = [];
foreach ($rows as $row) {
    $chartLabels[] = date('Y-m-d', strtotime($row['created_at'])) . ' · ' . $row['period_label'];
    $chartScores[] = $row['score'] !== null ? (int)$row['score'] : null;
}

$recommendedCourses = [];
if (!empty($user['work_function'])) {
    $courseStmt = $pdo->prepare('SELECT * FROM course_catalogue WHERE recommended_for=? AND min_score <= ? AND max_score >= ? ORDER BY min_score ASC');
    foreach ($latestScores as $scoreRow) {
        if ($scoreRow['score'] === null) {
            continue;
        }
        $score = (int)$scoreRow['score'];
        $courseStmt->execute([$user['work_function'], $score, $score]);
        foreach ($courseStmt->fetchAll() as $course) {
            $recommendedCourses[$course['id']] = $course;
        }
    }
}
$recommendedCourses = array_values($recommendedCourses);
$statusLabels = [
    'draft' => t($t, 'status_draft', 'Draft'),
    'submitted' => t($t, 'status_submitted', 'Submitted'),
    'approved' => t($t, 'status_approved', 'Approved'),
    'rejected' => t($t, 'status_rejected', 'Rejected'),
];

$flash = $_GET['msg'] ?? '';
$flashMessage = '';
if ($flash === 'submitted') {
    $flashMessage = t($t, 'submission_success', 'Assessment submitted successfully.');
}
?>
<!doctype html><html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>"><head>
<meta charset="utf-8"><title><?=htmlspecialchars(t($t,'my_performance','My Performance'), ENT_QUOTES, 'UTF-8')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<link rel="manifest" href="<?=asset_url('manifest.webmanifest')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head><body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/templates/header.php'; ?>
<section class="md-section">
  <?php if ($flashMessage): ?><div class="md-alert success"><?=htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'performance_overview','Performance Overview')?></h2>
    <p><?=t($t,'current_work_function','Current work function:')?> <?=htmlspecialchars(WORK_FUNCTION_LABELS[$user['work_function']] ?? $user['work_function'])?></p>
    <?php if ($latestEntry): ?>
      <p><?=t($t,'latest_submission','Latest submission:')?> <?=htmlspecialchars($latestEntry['period_label'])?> · <?=htmlspecialchars($latestEntry['title'])?> (<?= is_null($latestEntry['score']) ? '-' : (int)$latestEntry['score'] ?>%)</p>
    <?php else: ?>
      <p><?=t($t,'no_submissions_yet','No submissions recorded yet. Complete your first assessment to see insights.')?></p>
    <?php endif; ?>
    <?php if ($nextAssessmentDisplay): ?>
      <p><?=t($t,'next_assessment_scheduled','Next assessment scheduled:')?> <?=htmlspecialchars($nextAssessmentDisplay)?></p>
    <?php else: ?>
      <p class="md-muted"><?=t($t,'next_assessment_not_set','Your next assessment date has not been scheduled yet.')?></p>
    <?php endif; ?>
    <?php if ($draftResponses): ?>
      <div class="md-alert warning md-draft-alert">
        <strong><?=t($t,'draft_pending_title','Saved drafts awaiting submission')?>:</strong>
        <ul class="md-draft-list">
          <?php foreach ($draftResponses as $draft): ?>
            <li>
              <a href="<?=htmlspecialchars(url_for('submit_assessment.php?qid=' . $draft['questionnaire_id'] . '&performance_period_id=' . $draft['performance_period_id']), ENT_QUOTES, 'UTF-8')?>">
                <?=htmlspecialchars($draft['title'])?> · <?=htmlspecialchars($draft['period_label'])?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'your_trend','Your Score Trend')?></h2>
    <?php if ($chartLabels): ?>
      <div class="trend-chart-wrap">
        <canvas id="performance-trend-chart" height="220"></canvas>
      </div>
    <?php else: ?>
      <p><?=t($t,'no_trend_data','Submit assessments to generate your performance trend.')?></p>
    <?php endif; ?>
    <table class="md-table">
      <thead><tr><th><?=t($t,'date','Date')?></th><th><?=t($t,'questionnaire','Questionnaire')?></th><th><?=t($t,'performance_period','Performance Period')?></th><th><?=t($t,'score','Score (%)')?></th><th><?=t($t,'status','Status')?></th><th><?=t($t,'actions','Actions')?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          $statusKey = $r['status'] ?? 'submitted';
          $statusLabel = $statusLabels[$statusKey] ?? ucfirst($statusKey);
          $isDraft = ($statusKey === 'draft');
          $resumeLink = $isDraft ? url_for('submit_assessment.php?qid=' . $r['questionnaire_id'] . '&performance_period_id=' . $r['performance_period_id']) : null;
        ?>
        <tr>
          <td><?=htmlspecialchars($r['created_at'])?></td>
          <td><?=htmlspecialchars($r['title'])?></td>
          <td><?=htmlspecialchars($r['period_label'])?></td>
          <td><?= is_null($r['score']) ? '-' : (int)$r['score']?></td>
          <td><?=htmlspecialchars($statusLabel)?></td>
          <td>
            <?php if ($resumeLink): ?>
              <a class="md-button md-compact md-outline" href="<?=htmlspecialchars($resumeLink, ENT_QUOTES, 'UTF-8')?>"><?=t($t,'continue_draft','Continue Draft')?></a>
            <?php else: ?>
              <span class="md-muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'training_focus','Training Focus Areas')?></h2>
    <?php if ($belowThreshold): ?>
      <table class="md-table">
        <thead><tr><th><?=t($t,'questionnaire','Questionnaire')?></th><th><?=t($t,'performance_period','Period')?></th><th><?=t($t,'score','Score (%)')?></th></tr></thead>
        <tbody>
        <?php foreach ($belowThreshold as $item): ?>
          <tr>
            <td><?=htmlspecialchars($item['title'])?></td>
            <td><?=htmlspecialchars($item['period_label'])?></td>
            <td><?= (int)$item['score']?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p><?=t($t,'no_training_gaps','All recorded submissions achieved full marks. Great job!')?></p>
    <?php endif; ?>
  </div>
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'recommended_courses','Recommended Courses')?></h2>
    <?php if ($recommendedCourses): ?>
      <table class="md-table">
        <thead><tr><th><?=t($t,'course','Course')?></th><th><?=t($t,'link','Link')?></th><th><?=t($t,'score_band','Score Band')?></th></tr></thead>
        <tbody>
        <?php foreach ($recommendedCourses as $course): ?>
          <tr>
            <td><?=htmlspecialchars($course['title'])?></td>
            <td><a href="<?=htmlspecialchars($course['moodle_url'])?>" target="_blank" rel="noopener">Moodle</a></td>
            <td><?= (int)$course['min_score'] ?> - <?= (int)$course['max_score'] ?>%</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p><?=t($t,'no_courses_available','No targeted courses found for your current scores. Please contact your supervisor for tailored learning paths.')?></p>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__.'/templates/footer.php'; ?>
<?php
$chartLabelsJson = json_encode($chartLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$chartScoresJson = json_encode($chartScores, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
?>
<script src="<?=asset_url('assets/adminlte/plugins/chart.js/Chart.bundle.min.js')?>"></script>
<script>
(function() {
  const ctxElement = document.getElementById('performance-trend-chart');
  if (!ctxElement || typeof Chart === 'undefined') {
    return;
  }
  const labels = <?=$chartLabelsJson?>;
  const dataPoints = <?=$chartScoresJson?>;
  const ctx = ctxElement.getContext('2d');
  const styles = window.getComputedStyle(document.body);
  const primaryColor = (styles.getPropertyValue('--app-primary') || '#2073bf').trim() || '#2073bf';
  const softPrimary = (styles.getPropertyValue('--app-primary-soft') || '').trim();
  const hexToRgba = (hex, alpha) => {
    const cleaned = hex.replace('#', '');
    if (cleaned.length !== 6) {
      return `rgba(32,115,191,${alpha})`;
    }
    const bigint = parseInt(cleaned, 16);
    const r = (bigint >> 16) & 255;
    const g = (bigint >> 8) & 255;
    const b = bigint & 255;
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
  };
  const areaFill = softPrimary ? (softPrimary.startsWith('#') ? hexToRgba(softPrimary, 0.24) : softPrimary) : hexToRgba(primaryColor, 0.18);
  new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: 'Score (%)',
        data: dataPoints,
        borderColor: primaryColor,
        backgroundColor: areaFill,
        borderWidth: 3,
        pointRadius: 4,
        pointHoverRadius: 6,
        lineTension: 0.25,
        spanGaps: true,
      }],
    },
    options: {
      maintainAspectRatio: false,
      legend: {
        display: false,
      },
      tooltips: {
        callbacks: {
          label: function(tooltipItem) {
            const value = tooltipItem.yLabel;
            if (value === null || typeof value === 'undefined') {
              return 'No score';
            }
            return value + '%';
          },
        },
      },
      scales: {
        yAxes: [{
          ticks: {
            suggestedMin: 0,
            suggestedMax: 100,
          },
          gridLines: {
            color: 'rgba(13, 112, 56, 0.1)',
          },
        }],
        xAxes: [{
          gridLines: {
            display: false,
          },
          ticks: {
            autoSkip: true,
            maxTicksLimit: 8,
          },
        }],
      },
    },
  });
})();
</script>
</body></html>