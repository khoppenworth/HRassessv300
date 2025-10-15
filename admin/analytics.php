<?php
require_once __DIR__ . '/../config.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$summaryStmt = $pdo->query(
    "SELECT COUNT(*) AS total_responses, "
    . "SUM(status='approved') AS approved_count, "
    . "SUM(status='submitted') AS submitted_count, "
    . "SUM(status='draft') AS draft_count, "
    . "SUM(status='rejected') AS rejected_count, "
    . "AVG(score) AS avg_score, "
    . "MAX(created_at) AS latest_at "
    . "FROM questionnaire_response"
);
$summary = $summaryStmt ? $summaryStmt->fetch(PDO::FETCH_ASSOC) : [];

$totalParticipants = (int)($pdo->query('SELECT COUNT(DISTINCT user_id) FROM questionnaire_response')->fetchColumn() ?: 0);

$questionnaireStmt = $pdo->query(
    "SELECT q.id, q.title, COUNT(*) AS total_responses, "
    . "SUM(qr.status='approved') AS approved_count, "
    . "SUM(qr.status='submitted') AS submitted_count, "
    . "SUM(qr.status='draft') AS draft_count, "
    . "SUM(qr.status='rejected') AS rejected_count, "
    . "AVG(qr.score) AS avg_score "
    . "FROM questionnaire_response qr "
    . "JOIN questionnaire q ON q.id = qr.questionnaire_id "
    . "GROUP BY q.id, q.title "
    . "ORDER BY q.title"
);
$questionnaires = $questionnaireStmt ? $questionnaireStmt->fetchAll() : [];

$questionnaireIds = array_map(static fn($row) => (int)$row['id'], $questionnaires);
$selectedQuestionnaireId = (int)($_GET['questionnaire_id'] ?? 0);
if ($questionnaires) {
    if (!$selectedQuestionnaireId || !in_array($selectedQuestionnaireId, $questionnaireIds, true)) {
        $selectedQuestionnaireId = (int)$questionnaires[0]['id'];
    }
} else {
    $selectedQuestionnaireId = 0;
}

$selectedResponses = [];
$selectedUserBreakdown = [];
if ($selectedQuestionnaireId) {
    $responseStmt = $pdo->prepare(
        'SELECT qr.id, qr.status, qr.score, qr.created_at, qr.review_comment, '
        . 'u.username, u.full_name, u.work_function, pp.label AS period_label '
        . 'FROM questionnaire_response qr '
        . 'JOIN users u ON u.id = qr.user_id '
        . 'LEFT JOIN performance_period pp ON pp.id = qr.performance_period_id '
        . 'WHERE qr.questionnaire_id = ? '
        . 'ORDER BY qr.created_at DESC'
    );
    $responseStmt->execute([$selectedQuestionnaireId]);
    $selectedResponses = $responseStmt->fetchAll();

    $userStmt = $pdo->prepare(
        'SELECT u.id AS user_id, u.username, u.full_name, u.work_function, '
        . 'COUNT(*) AS total_responses, '
        . 'SUM(qr.status="approved") AS approved_count, '
        . 'AVG(qr.score) AS avg_score '
        . 'FROM questionnaire_response qr '
        . 'JOIN users u ON u.id = qr.user_id '
        . 'WHERE qr.questionnaire_id = ? '
        . 'GROUP BY u.id, u.username, u.full_name, u.work_function '
        . 'ORDER BY avg_score DESC'
    );
    $userStmt->execute([$selectedQuestionnaireId]);
    $selectedUserBreakdown = $userStmt->fetchAll();
}

$workFunctionOptions = work_function_choices($pdo);
$workFunctionStmt = $pdo->query(
    "SELECT u.work_function, COUNT(*) AS total_responses, "
    . "SUM(qr.status='approved') AS approved_count, "
    . "AVG(qr.score) AS avg_score "
    . "FROM questionnaire_response qr "
    . "JOIN users u ON u.id = qr.user_id "
    . "GROUP BY u.work_function "
    . "ORDER BY total_responses DESC"
);
$workFunctionSummary = $workFunctionStmt ? $workFunctionStmt->fetchAll() : [];

$questionnaireChartData = [];
foreach ($questionnaires as $row) {
    $questionnaireChartData[] = [
        'label' => (string)($row['title'] ?? ('Questionnaire ' . (int)$row['id'])),
        'score' => $row['avg_score'] !== null ? (float)$row['avg_score'] : null,
        'responses' => (int)($row['total_responses'] ?? 0),
    ];
}
$questionnaireChartData = array_values(array_filter($questionnaireChartData, static fn($row) => $row['score'] !== null));
usort($questionnaireChartData, static function ($a, $b) {
    $aScore = $a['score'] ?? 101;
    $bScore = $b['score'] ?? 101;
    if ($aScore === $bScore) {
        return strcmp($a['label'], $b['label']);
    }
    return $aScore <=> $bScore;
});
if (count($questionnaireChartData) > 12) {
    $questionnaireChartData = array_slice($questionnaireChartData, 0, 12);
}

$workFunctionChartData = [];
foreach ($workFunctionSummary as $row) {
    $wfKey = $row['work_function'] ?? '';
    $label = $workFunctionOptions[$wfKey] ?? ($wfKey !== '' ? (string)$wfKey : t($t, 'unknown', 'Unknown'));
    $workFunctionChartData[] = [
        'label' => (string)$label,
        'score' => $row['avg_score'] !== null ? (float)$row['avg_score'] : null,
        'responses' => (int)($row['total_responses'] ?? 0),
    ];
}
$workFunctionChartData = array_values(array_filter($workFunctionChartData, static fn($row) => $row['score'] !== null));
usort($workFunctionChartData, static function ($a, $b) {
    $aScore = $a['score'] ?? 101;
    $bScore = $b['score'] ?? 101;
    if ($aScore === $bScore) {
        return strcmp($a['label'], $b['label']);
    }
    return $aScore <=> $bScore;
});
if (count($workFunctionChartData) > 12) {
    $workFunctionChartData = array_slice($workFunctionChartData, 0, 12);
}

$chartJsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
if (defined('JSON_THROW_ON_ERROR')) {
    $chartJsonFlags |= JSON_THROW_ON_ERROR;
}
$hasAnalyticsCharts = !empty($questionnaireChartData) || !empty($workFunctionChartData);

$statusLabels = [
    'draft' => t($t, 'status_draft', 'Draft'),
    'submitted' => t($t, 'status_submitted', 'Submitted'),
    'approved' => t($t, 'status_approved', 'Approved'),
    'rejected' => t($t, 'status_rejected', 'Rejected'),
];

$formatScore = static function ($score, int $precision = 1): string {
    if ($score === null) {
        return '—';
    }
    return number_format((float)$score, $precision);
};

$selectedAggregate = [
    'total' => count($selectedResponses),
    'approved' => 0,
    'submitted' => 0,
    'draft' => 0,
    'rejected' => 0,
    'scored_count' => 0,
    'score_sum' => 0.0,
];
foreach ($selectedResponses as $row) {
    $statusKey = $row['status'] ?? '';
    if (isset($selectedAggregate[$statusKey])) {
        $selectedAggregate[$statusKey] += 1;
    }
    if (isset($row['score']) && $row['score'] !== null) {
        $selectedAggregate['score_sum'] += (float)$row['score'];
        $selectedAggregate['scored_count'] += 1;
    }
}
$selectedAverage = $selectedAggregate['scored_count'] > 0
    ? $selectedAggregate['score_sum'] / $selectedAggregate['scored_count']
    : null;
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t, 'analytics', 'Analytics'), ENT_QUOTES, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
  <link rel="manifest" href="<?=asset_url('manifest.php')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
  <style nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
    .md-summary-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1rem;
      margin: 1rem 0;
    }
    .md-summary-card {
      padding: 1rem;
      border-radius: 6px;
      background: var(--app-surface-alt, #f5f7fa);
    }
    .md-summary-card strong {
      display: block;
      font-size: 1.25rem;
      margin-bottom: 0.35rem;
    }
    .md-table--interactive tr.is-selected {
      background: rgba(0, 132, 255, 0.08);
    }
    .md-table--interactive tr.is-selected td {
      font-weight: 600;
    }
    .md-table--interactive a.md-row-link {
      display: inline-block;
      color: inherit;
      text-decoration: none;
    }
    .md-table--interactive a.md-row-link:hover,
    .md-table--interactive a.md-row-link:focus {
      text-decoration: underline;
    }
    .md-analytics-meta {
      margin: 0.75rem 0 0;
      color: var(--app-text-secondary, #555);
    }
    .md-analytics-meta--hint {
      margin-top: 0.35rem;
      font-size: 0.9rem;
    }
  </style>
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__ . '/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t, 'analytics_overview', 'Analytics overview')?></h2>
    <div class="md-summary-grid">
      <div class="md-summary-card">
        <strong><?= (int)($summary['total_responses'] ?? 0) ?></strong>
        <span><?=t($t, 'total_responses', 'Total responses recorded')?></span>
      </div>
      <div class="md-summary-card">
        <strong><?= $formatScore($summary['avg_score'] ?? null, 1) ?></strong>
        <span><?=t($t, 'average_score_all', 'Average score across all questionnaires')?></span>
      </div>
      <div class="md-summary-card">
        <strong><?= (int)($summary['approved_count'] ?? 0) ?></strong>
        <span><?=t($t, 'approved_responses', 'Approved responses')?></span>
      </div>
      <div class="md-summary-card">
        <strong><?= $totalParticipants ?></strong>
        <span><?=t($t, 'unique_participants', 'Unique participants')?></span>
      </div>
    </div>
    <?php if (!empty($summary['latest_at'])): ?>
      <p class="md-analytics-meta"><?=t($t, 'latest_submission', 'Latest submission:')?> <?=htmlspecialchars($summary['latest_at'], ENT_QUOTES, 'UTF-8')?></p>
    <?php endif; ?>
  </div>

  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t, 'questionnaire_performance', 'Questionnaire performance')?></h2>
    <?php if ($questionnaires): ?>
      <p class="md-upgrade-meta"><?=t($t, 'questionnaire_drilldown_hint', 'Select a questionnaire to drill into individual responses.')?></p>
      <?php if ($questionnaireChartData): ?>
        <div class="md-chart-container">
          <canvas id="questionnaire-performance-heatmap" role="img" aria-label="<?=htmlspecialchars(t($t, 'questionnaire_heatmap_alt', 'Horizontal bar chart highlighting questionnaire averages with heatmap colours.'), ENT_QUOTES, 'UTF-8')?>"></canvas>
        </div>
        <p class="md-analytics-meta md-analytics-meta--hint"><?=t($t, 'performance_heatmap_hint', 'Heatmap colours shift from red to green so low scores stand out for follow-up.')?></p>
      <?php endif; ?>
      <table class="md-table md-table--interactive">
        <thead>
          <tr>
            <th><?=t($t, 'questionnaire', 'Questionnaire')?></th>
            <th><?=t($t, 'count', 'Responses')?></th>
            <th><?=t($t, 'approved', 'Approved')?></th>
            <th><?=t($t, 'status_submitted', 'Submitted')?></th>
            <th><?=t($t, 'status_draft', 'Draft')?></th>
            <th><?=t($t, 'status_rejected', 'Rejected')?></th>
            <th><?=t($t, 'average_score', 'Average score (%)')?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($questionnaires as $row): ?>
            <?php $isSelected = ((int)$row['id'] === $selectedQuestionnaireId); ?>
            <tr class="<?= $isSelected ? 'is-selected' : '' ?>">
              <td>
                <a class="md-row-link" href="<?=htmlspecialchars(url_for('admin/analytics.php') . '?questionnaire_id=' . (int)$row['id'], ENT_QUOTES, 'UTF-8')?>">
                  <?=htmlspecialchars($row['title'] ?? '', ENT_QUOTES, 'UTF-8')?>
                </a>
              </td>
              <td><?= (int)$row['total_responses'] ?></td>
              <td><?= (int)$row['approved_count'] ?></td>
              <td><?= (int)$row['submitted_count'] ?></td>
              <td><?= (int)$row['draft_count'] ?></td>
              <td><?= (int)$row['rejected_count'] ?></td>
              <td><?= $formatScore($row['avg_score'] ?? null) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="md-upgrade-meta"><?=t($t, 'no_questionnaire_stats', 'No questionnaire responses are available yet.')?></p>
    <?php endif; ?>
  </div>

  <?php if ($selectedQuestionnaireId): ?>
    <div class="md-card md-elev-2">
      <?php
        $selectedQuestionnaire = null;
        foreach ($questionnaires as $candidate) {
            if ((int)$candidate['id'] === $selectedQuestionnaireId) {
                $selectedQuestionnaire = $candidate;
                break;
            }
        }
      ?>
      <h2 class="md-card-title">
        <?=t($t, 'responses_for_questionnaire', 'Responses for questionnaire')?> ·
        <?=htmlspecialchars($selectedQuestionnaire['title'] ?? '', ENT_QUOTES, 'UTF-8')?>
      </h2>
      <?php if ($selectedAggregate['total'] > 0): ?>
        <p class="md-upgrade-meta">
          <?=t($t, 'selected_summary', 'Average score: ')?>
          <?=$formatScore($selectedAverage)?> ·
          <?=t($t, 'approved_responses', 'Approved responses')?>: <?=$selectedAggregate['approved']?> ·
          <?=t($t, 'status_submitted', 'Submitted')?>: <?=$selectedAggregate['submitted']?> ·
          <?=t($t, 'status_draft', 'Draft')?>: <?=$selectedAggregate['draft']?> ·
          <?=t($t, 'status_rejected', 'Rejected')?>: <?=$selectedAggregate['rejected']?>
        </p>
        <table class="md-table">
          <thead>
            <tr>
              <th><?=t($t, 'user', 'User')?></th>
              <th><?=t($t, 'performance_period', 'Performance Period')?></th>
              <th><?=t($t, 'status', 'Status')?></th>
              <th><?=t($t, 'score', 'Score (%)')?></th>
              <th><?=t($t, 'date', 'Submitted on')?></th>
              <th><?=t($t, 'review_comment', 'Review comment')?></th>
              <th><?=t($t, 'view', 'View')?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($selectedResponses as $row): ?>
              <?php $statusKey = $row['status'] ?? ''; ?>
              <tr>
                <td>
                  <?=htmlspecialchars($row['username'] ?? '', ENT_QUOTES, 'UTF-8')?>
                  <?php if (!empty($row['full_name'])): ?>
                    <br><span class="md-muted"><?=htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8')?></span>
                  <?php endif; ?>
                </td>
                <td><?=htmlspecialchars($row['period_label'] ?? '', ENT_QUOTES, 'UTF-8')?></td>
                <td><?=htmlspecialchars($statusLabels[$statusKey] ?? ucfirst((string)$statusKey), ENT_QUOTES, 'UTF-8')?></td>
                <td><?= isset($row['score']) && $row['score'] !== null ? (int)$row['score'] : '—' ?></td>
                <td><?=htmlspecialchars($row['created_at'] ?? '', ENT_QUOTES, 'UTF-8')?></td>
                <td><?=htmlspecialchars($row['review_comment'] ?? '', ENT_QUOTES, 'UTF-8')?></td>
                <td><a class="md-button" href="<?=htmlspecialchars(url_for('admin/view_submission.php?id=' . (int)$row['id']), ENT_QUOTES, 'UTF-8')?>"><?=t($t, 'open', 'Open')?></a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p class="md-upgrade-meta"><?=t($t, 'no_responses_for_selection', 'There are no responses for this questionnaire yet.')?></p>
      <?php endif; ?>
    </div>

    <div class="md-card md-elev-2">
      <h2 class="md-card-title"><?=t($t, 'user_breakdown', 'Participant breakdown')?></h2>
      <?php if ($selectedUserBreakdown): ?>
        <table class="md-table">
          <thead>
            <tr>
              <th><?=t($t, 'user', 'User')?></th>
              <th><?=t($t, 'work_function', 'Work Function / Cadre')?></th>
              <th><?=t($t, 'count', 'Responses')?></th>
              <th><?=t($t, 'approved', 'Approved')?></th>
              <th><?=t($t, 'average_score', 'Average score (%)')?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($selectedUserBreakdown as $row): ?>
              <?php $workFunctionKey = $row['work_function'] ?? ''; ?>
              <tr>
                <td>
                  <?=htmlspecialchars($row['username'] ?? '', ENT_QUOTES, 'UTF-8')?>
                  <?php if (!empty($row['full_name'])): ?>
                    <br><span class="md-muted"><?=htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8')?></span>
                  <?php endif; ?>
                </td>
                <td><?=htmlspecialchars($workFunctionOptions[$workFunctionKey] ?? $workFunctionKey ?? '', ENT_QUOTES, 'UTF-8')?></td>
                <td><?= (int)$row['total_responses'] ?></td>
                <td><?= (int)$row['approved_count'] ?></td>
                <td><?= $formatScore($row['avg_score'] ?? null) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p class="md-upgrade-meta"><?=t($t, 'no_user_breakdown', 'No participant data available for this questionnaire.')?></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t, 'work_function_performance', 'Work Function Performance')?></h2>
    <?php if ($workFunctionSummary): ?>
      <?php if ($workFunctionChartData): ?>
        <div class="md-chart-container">
          <canvas id="work-function-heatmap" role="img" aria-label="<?=htmlspecialchars(t($t, 'work_function_heatmap_alt', 'Horizontal bar chart comparing work function averages using heatmap colours.'), ENT_QUOTES, 'UTF-8')?>"></canvas>
        </div>
      <?php endif; ?>
      <table class="md-table">
        <thead>
          <tr>
            <th><?=t($t, 'work_function', 'Work Function / Cadre')?></th>
            <th><?=t($t, 'count', 'Responses')?></th>
            <th><?=t($t, 'approved', 'Approved')?></th>
            <th><?=t($t, 'average_score', 'Average score (%)')?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($workFunctionSummary as $row): ?>
            <?php $wfKey = $row['work_function'] ?? ''; ?>
            <tr>
              <td><?=htmlspecialchars($workFunctionOptions[$wfKey] ?? ($wfKey !== '' ? $wfKey : t($t, 'unknown', 'Unknown')), ENT_QUOTES, 'UTF-8')?></td>
              <td><?= (int)$row['total_responses'] ?></td>
              <td><?= (int)$row['approved_count'] ?></td>
              <td><?= $formatScore($row['avg_score'] ?? null) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="md-upgrade-meta"><?=t($t, 'work_function_empty', 'Assign questionnaires to teams to see benchmarks populate here.')?></p>
    <?php endif; ?>
  </div>
</section>
<?php if ($hasAnalyticsCharts): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" integrity="sha384-EtBsuD6bYDI7ilMWVT09G/1nHQRE8PbtY7TIn4lZG3Fjm1fvcDUoJ7Sm9Ua+bJOy" crossorigin="anonymous"></script>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
  (function () {
    const questionnaireHeatmap = <?=json_encode([
      'labels' => array_column($questionnaireChartData, 'label'),
      'scores' => array_map(static fn($row) => $row['score'], $questionnaireChartData),
      'counts' => array_map(static fn($row) => $row['responses'], $questionnaireChartData),
    ], $chartJsonFlags)?>;
    const workFunctionHeatmap = <?=json_encode([
      'labels' => array_column($workFunctionChartData, 'label'),
      'scores' => array_map(static fn($row) => $row['score'], $workFunctionChartData),
      'counts' => array_map(static fn($row) => $row['responses'], $workFunctionChartData),
    ], $chartJsonFlags)?>;
    const labels = {
      averageScore: <?=json_encode(t($t, 'average_score', 'Average score (%)'), $chartJsonFlags)?>,
      responses: <?=json_encode(t($t, 'count', 'Responses'), $chartJsonFlags)?>,
    };

    const heatStops = [
      { stop: 0, color: [211, 47, 47] },
      { stop: 0.5, color: [249, 168, 37] },
      { stop: 1, color: [46, 125, 50] },
    ];

    const clamp = (value, min, max) => Math.min(Math.max(value, min), max);
    const mix = (start, end, ratio) => Math.round(start + (end - start) * ratio);

    function heatColor(score, alpha = 0.85) {
      if (typeof score !== 'number' || Number.isNaN(score)) {
        score = 0;
      }
      const normalized = clamp(score / 100, 0, 1);
      let left = heatStops[0];
      let right = heatStops[heatStops.length - 1];
      for (let i = 0; i < heatStops.length - 1; i += 1) {
        const current = heatStops[i];
        const next = heatStops[i + 1];
        if (normalized >= current.stop && normalized <= next.stop) {
          left = current;
          right = next;
          break;
        }
      }
      const range = right.stop - left.stop || 1;
      const ratio = clamp((normalized - left.stop) / range, 0, 1);
      const r = mix(left.color[0], right.color[0], ratio);
      const g = mix(left.color[1], right.color[1], ratio);
      const b = mix(left.color[2], right.color[2], ratio);
      return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }

    function renderHeatmap(targetId, dataset, options = {}) {
      if (!dataset || !Array.isArray(dataset.labels) || !dataset.labels.length) {
        return;
      }
      const canvas = document.getElementById(targetId);
      if (!canvas) {
        return;
      }
      const context = canvas.getContext('2d');
      if (!context) {
        return;
      }
      const scores = dataset.scores.map((score) => (typeof score === 'number' ? score : 0));
      const colors = scores.map((score) => heatColor(score, 0.8));
      const borderColors = scores.map((score) => heatColor(score, 1));
      const counts = dataset.counts || [];
      new Chart(canvas, {
        type: 'bar',
        data: {
          labels: dataset.labels,
          datasets: [{
            data: scores,
            backgroundColor: colors,
            borderColor: borderColors,
            borderWidth: 1.5,
            borderRadius: 6,
            barPercentage: 0.75,
          }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          indexAxis: options.indexAxis || 'y',
          scales: {
            x: {
              beginAtZero: true,
              max: 100,
              ticks: {
                callback: (value) => `${value}%`,
              },
              grid: { color: 'rgba(17, 56, 94, 0.08)' },
              title: options.indexAxis === 'y' ? { display: true, text: labels.averageScore } : undefined,
            },
            y: {
              ticks: { autoSkip: false },
              grid: { display: false },
            },
          },
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: (context) => {
                  const value = typeof context.parsed.x === 'number' ? context.parsed.x.toFixed(1) : context.parsed.x;
                  const count = counts[context.dataIndex];
                  const countText = typeof count === 'number' ? ` · ${count} ${labels.responses}` : '';
                  return `${context.label}: ${value}%${countText}`;
                },
              },
            },
          },
        },
      });
    }

    document.addEventListener('DOMContentLoaded', () => {
      if (!window.Chart) {
        return;
      }
      if (questionnaireHeatmap.labels && questionnaireHeatmap.labels.length) {
        renderHeatmap('questionnaire-performance-heatmap', questionnaireHeatmap, { indexAxis: 'y' });
      }
      if (workFunctionHeatmap.labels && workFunctionHeatmap.labels.length) {
        renderHeatmap('work-function-heatmap', workFunctionHeatmap, { indexAxis: 'y' });
      }
    });
  })();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
