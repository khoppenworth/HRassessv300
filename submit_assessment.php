<?php
require_once __DIR__ . '/config.php';
auth_required(['staff','supervisor','admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$err = '';

$user = current_user();
$questionnaireSql = "SELECT DISTINCT q.id, q.title FROM questionnaire q";
if ($user['role'] === 'staff') {
    $questionnaireSql .= " JOIN questionnaire_work_function qw ON qw.questionnaire_id = q.id WHERE qw.work_function = :wf";
    $questionnaireSql .= " ORDER BY q.title";
    $stmt = $pdo->prepare($questionnaireSql);
    $stmt->execute(['wf' => $user['work_function']]);
    $q = $stmt->fetchAll();
} else {
    $q = $pdo->query("SELECT id, title FROM questionnaire ORDER BY title")->fetchAll();
}
$periods = $pdo->query("SELECT id, label FROM performance_period ORDER BY period_start DESC")->fetchAll();
$qid = (int)($_GET['qid'] ?? ($q[0]['id'] ?? 0));
$periodId = (int)($_GET['performance_period_id'] ?? ($periods[0]['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $qid = (int)($_POST['qid'] ?? 0);
    $periodId = (int)($_POST['performance_period_id'] ?? 0);
    $check = $pdo->prepare('SELECT COUNT(*) FROM questionnaire_response WHERE user_id=? AND questionnaire_id=? AND performance_period_id=?');
    $check->execute([$user['id'], $qid, $periodId]);
    if ($check->fetchColumn() > 0) {
        $err = t($t,'duplicate_submission','A submission already exists for the selected performance period.');
    } elseif (!$periodId) {
        $err = t($t,'select_period','Please select a performance period.');
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO questionnaire_response (user_id, questionnaire_id, performance_period_id, status, created_at) VALUES (?,?,?, "submitted", NOW())');
            $stmt->execute([$user['id'], $qid, $periodId]);
            $rid = (int)$pdo->lastInsertId();

            // Fetch items with weights
            $items = $pdo->prepare('SELECT linkId, type, COALESCE(weight_percent,0) AS weight_percent FROM questionnaire_item WHERE questionnaire_id=? ORDER BY order_index ASC');
            $items->execute([$qid]);
            $items = $items->fetchAll();

            $score_sum = 0.0;
            $weight_sum = 0.0;

            foreach ($items as $it) {
                $name = 'item_' . $it['linkId'];
                $ans = $_POST[$name] ?? '';
                $weight = (float)$it['weight_percent'];
                $achieved = 0.0;

                if ($it['type'] === 'boolean') {
                    $val = ($ans === '1' || $ans === 'true' || $ans === 'on') ? 'true' : 'false';
                    $achieved = ($val === 'true') ? $weight : 0.0;
                    $a = json_encode([['valueBoolean' => $val === 'true']]);
                } else {
                    $txt = trim((string)$ans);
                    $achieved = ($txt !== '') ? $weight : 0.0;
                    $a = json_encode([['valueString' => $txt]]);
                }
                $ins = $pdo->prepare('INSERT INTO questionnaire_response_item (response_id, linkId, answer) VALUES (?,?,?)');
                $ins->execute([$rid, $it['linkId'], $a]);

                $score_sum += $achieved;
                $weight_sum += $weight;
            }
            $pct = $weight_sum > 0 ? (int)round(($score_sum / $weight_sum) * 100) : null;
            $pdo->prepare('UPDATE questionnaire_response SET score=? WHERE id=?')->execute([$pct, $rid]);
            $pdo->commit();
            header('Location: ' . url_for('my_performance.php?msg=submitted'));
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('submit_assessment failed: ' . $e->getMessage());
            $err = t($t, 'submission_failed', 'We could not save your responses. Please try again.');
        }
    }
}

// Load selected questionnaire with sections and items
$sections = []; $items = [];
$availablePeriods = $periods;
$taken = [];
if ($qid) {
    $s = $pdo->prepare("SELECT * FROM questionnaire_section WHERE questionnaire_id=? ORDER BY order_index ASC");
    $s->execute([$qid]); $sections = $s->fetchAll();
    $i = $pdo->prepare("SELECT * FROM questionnaire_item WHERE questionnaire_id=? ORDER BY order_index ASC");
    $i->execute([$qid]); $items = $i->fetchAll();
    $takenStmt = $pdo->prepare('SELECT performance_period_id FROM questionnaire_response WHERE user_id=? AND questionnaire_id=?');
    $takenStmt->execute([$user['id'], $qid]);
    $taken = array_column($takenStmt->fetchAll(), 'performance_period_id');
    $availablePeriods = array_values(array_filter($periods, static fn($p) => !in_array($p['id'], $taken, true)));
    if (!$periodId || in_array($periodId, $taken, true)) {
        $periodId = $availablePeriods[0]['id'] ?? 0;
    }
}
?>
<!doctype html><html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>"><head>
<meta charset="utf-8"><title><?=htmlspecialchars(t($t,'submit_assessment','Submit Assessment'), ENT_QUOTES, 'UTF-8')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<link rel="manifest" href="<?=asset_url('manifest.webmanifest')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head><body class="md-bg">
<?php include __DIR__.'/templates/header.php'; ?>
<section class="md-section">
<div class="md-card md-elev-2">
  <h2 class="md-card-title"><?=t($t,'submit_assessment','Submit Assessment')?></h2>
  <?php if (!empty($err)): ?><div class="md-alert"><?=htmlspecialchars($err, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
  <form method="get" class="md-inline-form" action="<?=htmlspecialchars(url_for('submit_assessment.php'), ENT_QUOTES, 'UTF-8')?>">
    <label class="md-field">
      <span><?=t($t,'select_questionnaire','Select questionnaire')?></span>
      <select name="qid" onchange="this.form.submit()">
        <?php foreach ($q as $row): ?>
          <option value="<?=$row['id']?>" <?=($row['id']==$qid?'selected':'')?>><?=htmlspecialchars($row['title'])?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="md-field">
      <span><?=t($t,'performance_period','Performance Period')?></span>
      <select name="performance_period_id" onchange="this.form.submit()">
        <?php foreach ($periods as $period): ?>
          <?php $disabled = in_array($period['id'], $taken, true); ?>
          <option value="<?=$period['id']?>" <?=($period['id']==$periodId?'selected':'')?> <?=$disabled?'disabled':''?>><?=htmlspecialchars($period['label'])?><?=$disabled?' · '.t($t,'already_submitted','Submitted'):''?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
  <?php if ($qid && empty($availablePeriods)): ?>
    <p><?=t($t,'all_periods_used','You have already submitted for every period available for this questionnaire.')?></p>
  <?php elseif ($qid): ?>
  <form method="post" action="<?=htmlspecialchars(url_for('submit_assessment.php'), ENT_QUOTES, 'UTF-8')?>">
    <input type="hidden" name="csrf" value="<?=csrf_token()?>">
    <input type="hidden" name="qid" value="<?=$qid?>">
    <input type="hidden" name="performance_period_id" value="<?=$periodId?>">
    <?php foreach ($sections as $sec): ?>
      <h3 class="md-section-title"><?=htmlspecialchars($sec['title'])?></h3>
      <p class="md-muted"><?=htmlspecialchars($sec['description'])?></p>
      <div class="md-divider"></div>
      <?php foreach ($items as $it): if ((int)$it['section_id'] !== (int)$sec['id']) continue; ?>
        <label class="md-field">
          <span><?=htmlspecialchars($it['text'])?></span>
          <?php if ($it['type']==='boolean'): ?>
            <input type="checkbox" name="item_<?=$it['linkId']?>">
          <?php elseif ($it['type']==='textarea'): ?>
            <textarea name="item_<?=$it['linkId']?>" rows="3"></textarea>
          <?php else: ?>
            <input name="item_<?=$it['linkId']?>">
          <?php endif; ?>
          <?php if (!is_null($it['weight_percent'])): ?>
            <small class="md-hint">Weight: <?= (int)$it['weight_percent']?>%</small>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <button class="md-button md-primary md-elev-2"><?=t($t,'submit','Submit')?></button>
  </form>
  <?php else: ?>
    <p><?=t($t,'no_questionnaire','No questionnaire found.')?></p>
  <?php endif; ?>
</div>
</section>
<?php include __DIR__.'/templates/footer.php'; ?>
</body></html>