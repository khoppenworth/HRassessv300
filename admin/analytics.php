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
          <?=t($t, 'selected_summary', 'Average score:')?>
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
<?php include __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
