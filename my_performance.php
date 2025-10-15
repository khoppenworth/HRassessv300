<?php
require_once __DIR__ . '/config.php';
auth_required(['staff','supervisor','admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$user = current_user();
$userWorkFunctionLabel = work_function_label($pdo, (string)($user['work_function'] ?? ''));

function compute_section_breakdowns(PDO $pdo, array $responses, array $translations): array
{
    if (!$responses) {
        return [];
    }

    $questionnaireIds = [];
    $responseMeta = [];
    foreach ($responses as $response) {
        if (!is_array($response)) {
            continue;
        }
        $responseId = isset($response['id']) ? (int)$response['id'] : 0;
        $questionnaireId = isset($response['questionnaire_id']) ? (int)$response['questionnaire_id'] : 0;
        if ($responseId <= 0 || $questionnaireId <= 0) {
            continue;
        }
        $questionnaireIds[$questionnaireId] = true;
        $responseMeta[$responseId] = [
            'questionnaire_id' => $questionnaireId,
            'title' => (string)($response['title'] ?? ''),
            'period' => $response['period_label'] ?? null,
        ];
    }

    if (!$responseMeta) {
        return [];
    }

    $qidList = array_keys($questionnaireIds);
    $placeholder = implode(',', array_fill(0, count($qidList), '?'));

    $sectionsByQuestionnaire = [];
    if ($placeholder !== '') {
        $sectionsStmt = $pdo->prepare(
            "SELECT id, questionnaire_id, title, order_index FROM questionnaire_section " .
            "WHERE questionnaire_id IN ($placeholder) ORDER BY questionnaire_id, order_index, id"
        );
        $sectionsStmt->execute($qidList);
        foreach ($sectionsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $qid = (int)$row['questionnaire_id'];
            $sectionsByQuestionnaire[$qid][] = [
                'id' => (int)$row['id'],
                'title' => $row['title'] ?? '',
            ];
        }

        $itemsStmt = $pdo->prepare(
            "SELECT questionnaire_id, section_id, linkId, type, allow_multiple, " .
            "COALESCE(weight_percent,0) AS weight FROM questionnaire_item " .
            "WHERE questionnaire_id IN ($placeholder) ORDER BY questionnaire_id, order_index, id"
        );
        $itemsStmt->execute($qidList);
    } else {
        $itemsStmt = $pdo->prepare('SELECT 1 WHERE 0');
        $itemsStmt->execute();
    }

    $itemsByQuestionnaire = [];
    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $qid = (int)$row['questionnaire_id'];
        $sectionId = $row['section_id'] !== null ? (int)$row['section_id'] : null;
        $itemsByQuestionnaire[$qid][] = [
            'section_id' => $sectionId,
            'linkId' => (string)$row['linkId'],
            'type' => (string)$row['type'],
            'allow_multiple' => (bool)$row['allow_multiple'],
            'weight' => (float)$row['weight'],
        ];
    }

    $responseIds = array_keys($responseMeta);
    $answersByResponse = [];
    if ($responseIds) {
        $answerPlaceholder = implode(',', array_fill(0, count($responseIds), '?'));
        $answerStmt = $pdo->prepare(
            "SELECT response_id, linkId, answer FROM questionnaire_response_item " .
            "WHERE response_id IN ($answerPlaceholder)"
        );
        $answerStmt->execute($responseIds);
        foreach ($answerStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rid = (int)$row['response_id'];
            $decoded = json_decode($row['answer'] ?? '[]', true);
            if (!is_array($decoded)) {
                $decoded = [];
            }
            $answersByResponse[$rid][$row['linkId']] = $decoded;
        }
    }

    $sectionBreakdowns = [];
    $generalLabel = t($translations, 'unassigned_section_label', 'General');
    $sectionFallback = t($translations, 'section_placeholder', 'Section');
    $questionnaireFallback = t($translations, 'questionnaire_placeholder', 'Questionnaire');

    $scoreCalculator = static function (array $item, array $answerSet, float $weight): float {
        $type = (string)($item['type'] ?? 'text');
        if ($weight <= 0) {
            return 0.0;
        }
        if ($type === 'boolean') {
            foreach ($answerSet as $entry) {
                if ((isset($entry['valueBoolean']) && $entry['valueBoolean']) ||
                    (isset($entry['valueString']) && strtolower((string)$entry['valueString']) === 'true')) {
                    return $weight;
                }
            }
            return 0.0;
        }
        if ($type === 'likert') {
            $score = null;
            foreach ($answerSet as $entry) {
                if (isset($entry['valueInteger']) && is_numeric($entry['valueInteger'])) {
                    $score = (int)$entry['valueInteger'];
                    break;
                }
                if (isset($entry['valueString'])) {
                    $candidate = trim((string)$entry['valueString']);
                    if (preg_match('/^([1-5])/', $candidate, $matches)) {
                        $score = (int)$matches[1];
                        break;
                    }
                    if (is_numeric($candidate)) {
                        $value = (int)$candidate;
                        if ($value >= 1 && $value <= 5) {
                            $score = $value;
                            break;
                        }
                    }
                }
            }
            if ($score !== null && $score >= 1 && $score <= 5) {
                return $weight * $score / 5.0;
            }
            return 0.0;
        }
        if ($type === 'choice') {
            foreach ($answerSet as $entry) {
                if (isset($entry['valueString']) && trim((string)$entry['valueString']) !== '') {
                    return $weight;
                }
            }
            return 0.0;
        }
        foreach ($answerSet as $entry) {
            if (isset($entry['valueString']) && trim((string)$entry['valueString']) !== '') {
                return $weight;
            }
        }
        return 0.0;
    };

    foreach ($responseMeta as $responseId => $meta) {
        $qid = $meta['questionnaire_id'];
        $items = $itemsByQuestionnaire[$qid] ?? [];
        if (!$items) {
            continue;
        }
        $sectionStats = [];
        $orderedSections = [];
        foreach ($sectionsByQuestionnaire[$qid] ?? [] as $section) {
            $sid = $section['id'];
            $sectionStats[$sid] = [
                'label' => (string)$section['title'],
                'weight' => 0.0,
                'achieved' => 0.0,
            ];
            $orderedSections[] = $sid;
        }
        $unassignedKey = 'unassigned';
        $sectionStats[$unassignedKey] = [
            'label' => $generalLabel,
            'weight' => 0.0,
            'achieved' => 0.0,
        ];

        $answers = $answersByResponse[$responseId] ?? [];
        foreach ($items as $item) {
            $sectionKey = $item['section_id'] ?? $unassignedKey;
            if (!isset($sectionStats[$sectionKey])) {
                $sectionStats[$sectionKey] = [
                    'label' => $sectionFallback,
                    'weight' => 0.0,
                    'achieved' => 0.0,
                ];
                if ($sectionKey !== $unassignedKey) {
                    $orderedSections[] = $sectionKey;
                }
            }
            $weight = (float)$item['weight'];
            if ($weight <= 0) {
                continue;
            }
            $sectionStats[$sectionKey]['weight'] += $weight;
            $answerSet = $answers[$item['linkId']] ?? [];
            $sectionStats[$sectionKey]['achieved'] += $scoreCalculator($item, $answerSet, $weight);
        }

        $sections = [];
        foreach ($orderedSections as $sid) {
            $stat = $sectionStats[$sid] ?? null;
            if (!$stat || $stat['weight'] <= 0) {
                continue;
            }
            $label = trim((string)$stat['label']);
            if ($label === '') {
                $label = $sectionFallback;
            }
            $sections[] = [
                'label' => $label,
                'score' => round(($stat['achieved'] / $stat['weight']) * 100, 1),
            ];
        }

        if ($sectionStats[$unassignedKey]['weight'] > 0) {
            $sections[] = [
                'label' => $sectionStats[$unassignedKey]['label'],
                'score' => round(($sectionStats[$unassignedKey]['achieved'] / $sectionStats[$unassignedKey]['weight']) * 100, 1),
            ];
        }

        if ($sections) {
            $title = trim($meta['title']);
            if ($title === '') {
                $title = $questionnaireFallback;
            }
            $sectionBreakdowns[$qid] = [
                'title' => $title,
                'period' => $meta['period'] ? (string)$meta['period'] : null,
                'sections' => $sections,
            ];
        }
    }

    return $sectionBreakdowns;
}

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

$sectionBreakdowns = compute_section_breakdowns($pdo, array_values($latestScores), $t);
$chartDataFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
if (defined('JSON_THROW_ON_ERROR')) {
    $chartDataFlags |= JSON_THROW_ON_ERROR;
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
<link rel="manifest" href="<?=asset_url('manifest.php')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head><body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/templates/header.php'; ?>
<section class="md-section">
  <?php if ($flashMessage): ?><div class="md-alert success"><?=htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'performance_overview','Performance Overview')?></h2>
    <p><?=t($t,'current_work_function','Current work function:')?> <?=htmlspecialchars($userWorkFunctionLabel !== '' ? $userWorkFunctionLabel : (string)($user['work_function'] ?? ''), ENT_QUOTES, 'UTF-8')?></p>
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
  <?php if ($sectionBreakdowns): ?>
    <div class="md-card md-elev-2">
      <h2 class="md-card-title"><?=t($t,'section_breakdown','Section score radar')?></h2>
      <p><?=t($t,'section_breakdown_hint','Each radar shows how your latest submission performed across questionnaire sections.')?></p>
      <div class="md-radar-grid">
        <?php foreach ($sectionBreakdowns as $qid => $radar): ?>
          <div class="md-radar-card">
            <h3 class="md-radar-title"><?=htmlspecialchars($radar['title'], ENT_QUOTES, 'UTF-8')?></h3>
            <?php if (!empty($radar['period'])): ?>
              <p class="md-radar-meta"><?=htmlspecialchars($radar['period'], ENT_QUOTES, 'UTF-8')?></p>
            <?php endif; ?>
            <div class="md-radar-canvas">
              <canvas id="radar-chart-<?=$qid?>" role="img" aria-label="<?=htmlspecialchars(sprintf(t($t,'section_score_chart_alt','Section scores for %s'), $radar['title']), ENT_QUOTES, 'UTF-8')?>"></canvas>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'your_trend','Your Score Trend')?></h2>
  <?php if ($chartLabels): ?>
      <div class="trend-chart-wrap">
        <canvas
          id="performance-timeline-chart"
          role="img"
          aria-label="<?=htmlspecialchars(t($t,'performance_timeline_alt','Line chart showing your performance timeline'), ENT_QUOTES, 'UTF-8')?>"
        ></canvas>
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
<?php $hasChartJs = !empty($chartLabels) || !empty($sectionBreakdowns); ?>
<?php if ($hasChartJs): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" integrity="sha384-EtBsuD6bYDI7ilMWVT09G/1nHQRE8PbtY7TIn4lZG3Fjm1fvcDUoJ7Sm9Ua+bJOy" crossorigin="anonymous"></script>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
  (function () {
    const timelineData = <?=json_encode([
      'labels' => $chartLabels,
      'scores' => array_map(static fn($score) => $score === null ? null : (float)$score, $chartScores),
    ], $chartDataFlags)?>;
    const radarData = <?=json_encode($sectionBreakdowns, $chartDataFlags)?>;
    const rootStyles = getComputedStyle(document.documentElement);

    const cssVar = (name, fallback) => {
      const value = rootStyles.getPropertyValue(name);
      if (value && value.trim()) {
        return value.trim();
      }
      if (fallback) {
        const fallbackValue = rootStyles.getPropertyValue(fallback);
        if (fallbackValue && fallbackValue.trim()) {
          return fallbackValue.trim();
        }
      }
      return '';
    };

    const radarPalette = [
      { bg: cssVar('--app-primary-soft'), border: cssVar('--app-primary') },
      { bg: cssVar('--status-warning-soft'), border: cssVar('--status-warning') },
      { bg: cssVar('--status-success-soft'), border: cssVar('--status-success') },
      { bg: cssVar('--status-info-soft'), border: cssVar('--status-info', '--app-secondary') }
    ].filter((entry) => entry.bg && entry.border);
    if (!radarPalette.length) {
      radarPalette.push({ bg: cssVar('--app-primary-soft'), border: cssVar('--app-primary') });
    }

    const heatStops = [
      { stop: 0, color: [211, 47, 47] },
      { stop: 0.5, color: [249, 168, 37] },
      { stop: 1, color: [46, 125, 50] }
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

    function renderTimeline() {
      const labels = Array.isArray(timelineData.labels) ? timelineData.labels : [];
      const scores = Array.isArray(timelineData.scores) ? timelineData.scores : [];
      if (!labels.length) {
        return;
      }
      const canvas = document.getElementById('performance-timeline-chart');
      if (!canvas) {
        return;
      }
      const context = canvas.getContext('2d');
      if (!context) {
        return;
      }
      const gradient = context.createLinearGradient(0, 0, 0, canvas.height || 320);
      gradient.addColorStop(0, 'rgba(46, 125, 50, 0.25)');
      gradient.addColorStop(1, 'rgba(211, 47, 47, 0.05)');

      new Chart(canvas, {
        type: 'line',
        data: {
          labels,
          datasets: [{
            data: scores,
            fill: true,
            backgroundColor: gradient,
            borderColor: 'rgba(25, 89, 147, 0.85)',
            borderWidth: 3,
            pointBackgroundColor: scores.map((score) => heatColor(score, 1)),
            pointBorderColor: cssVar('--app-surface', '--brand-bg') || '#ffffff',
            pointRadius: 4,
            pointHoverRadius: 6,
            pointHoverBorderWidth: 2,
            segment: {
              borderColor: (ctx) => heatColor(resolveSegmentScore(ctx, scores), 0.9),
            },
          }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { intersect: false, mode: 'nearest' },
          elements: {
            line: { tension: 0.35 },
          },
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: (context) => {
                  const value = typeof context.parsed.y === 'number' ? context.parsed.y.toFixed(1) : context.parsed.y;
                  return `${context.label}: ${value}%`;
                },
              },
            },
          },
          scales: {
            y: {
              beginAtZero: true,
              max: 100,
              ticks: {
                callback: (value) => `${value}%`,
              },
              grid: { color: 'rgba(17, 56, 94, 0.08)' },
            },
            x: {
              ticks: { maxRotation: 45, minRotation: 0, autoSkip: true },
              grid: { display: false },
            },
          },
        },
      });
    }

    function resolveSegmentScore(context, scores) {
      const forward = scores[context.p1DataIndex];
      if (typeof forward === 'number') {
        return forward;
      }
      const backward = scores[context.p0DataIndex];
      if (typeof backward === 'number') {
        return backward;
      }
      return 0;
    }

    function renderRadars() {
      if (!radarData) {
        return;
      }
      let paletteIndex = 0;
      Object.keys(radarData).forEach((qid) => {
        const canvas = document.getElementById(`radar-chart-${qid}`);
        if (!canvas) {
          return;
        }
        const dataset = radarData[qid];
        if (!dataset || !Array.isArray(dataset.sections) || !dataset.sections.length) {
          return;
        }
        const labels = dataset.sections.map((section) => section.label);
        const values = dataset.sections.map((section) => Number(section.score) || 0);
        const colors = radarPalette[paletteIndex % radarPalette.length];
        paletteIndex += 1;
        new Chart(canvas, {
          type: 'radar',
          data: {
            labels,
            datasets: [{
              label: dataset.title,
              data: values,
              fill: true,
              backgroundColor: colors.bg,
              borderColor: colors.border,
              borderWidth: 2,
              pointBackgroundColor: colors.border,
              pointBorderColor: cssVar('--app-surface', '--brand-bg'),
              pointRadius: 4,
              pointHoverRadius: 5,
            }],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: { display: false },
              tooltip: {
                callbacks: {
                  label: (context) => {
                    const raw = context.parsed && typeof context.parsed.r === 'number' ? context.parsed.r : context.parsed;
                    const rounded = typeof raw === 'number' ? raw.toFixed(1) : raw;
                    return `${context.label}: ${rounded}%`;
                  },
                },
              },
            },
            scales: {
              r: {
                suggestedMin: 0,
                suggestedMax: 100,
                ticks: {
                  stepSize: 20,
                  showLabelBackdrop: false,
                  callback: (value) => `${value}%`,
                },
                grid: { color: 'rgba(32, 115, 191, 0.15)' },
                angleLines: { color: 'rgba(32, 115, 191, 0.2)' },
              },
            },
          },
        });
      });
    }

    document.addEventListener('DOMContentLoaded', () => {
      if (!window.Chart) {
        return;
      }
      renderTimeline();
      renderRadars();
    });
  })();
</script>
<?php endif; ?>
<?php include __DIR__.'/templates/footer.php'; ?>
</body></html>
