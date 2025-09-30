<?php
require_once __DIR__.'/config.php';
auth_required(['staff','supervisor','admin']);
$t = load_lang($_SESSION['lang'] ?? 'en');

$q = $pdo->query("SELECT id, title FROM questionnaire ORDER BY id DESC")->fetchAll();
$qid = (int)($_GET['qid'] ?? ($q[0]['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $qid = (int)($_POST['qid'] ?? 0);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO questionnaire_response (user_id, questionnaire_id, status, created_at) VALUES (?,?, 'submitted', NOW())");
        $stmt->execute([$_SESSION['user']['id'], $qid]);
        $rid = (int)$pdo->lastInsertId();

        // Fetch items with weights
        $items = $pdo->prepare("SELECT linkId, type, COALESCE(weight_percent,0) AS weight_percent FROM questionnaire_item WHERE questionnaire_id=? ORDER BY order_index ASC");
        $items->execute([$qid]);
        $items = $items->fetchAll();

        $score_sum = 0.0;
        $weight_sum = 0.0;

        foreach ($items as $it) {
            $name = 'item_'.$it['linkId'];
            $ans = $_POST[$name] ?? '';
            $weight = (float)$it['weight_percent'];
            $achieved = 0.0;

            if ($it['type']==='boolean') {
                $val = ($ans==='1' || $ans==='true' || $ans==='on') ? 'true' : 'false';
                $achieved = ($val==='true') ? $weight : 0.0;
                $a = json_encode([['valueBoolean'=>$val==='true']]);
            } else {
                $txt = trim((string)$ans);
                $achieved = ($txt!=='') ? $weight : 0.0;
                $a = json_encode([['valueString'=>$txt]]);
            }
            $ins = $pdo->prepare("INSERT INTO questionnaire_response_item (response_id, linkId, answer) VALUES (?,?,?)");
            $ins->execute([$rid, $it['linkId'], $a]);

            $score_sum += $achieved;
            $weight_sum += $weight;
        }
        $pct = $weight_sum > 0 ? (int)round(($score_sum / $weight_sum) * 100) : null;
        $pdo->prepare("UPDATE questionnaire_response SET score=? WHERE id=?")->execute([$pct, $rid]);
        $pdo->commit();
        header("Location: performance.php?msg=submitted");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $err = 'Error: ' . $e->getMessage();
    }
}

// Load selected questionnaire with sections and items
$sections = []; $items = [];
if ($qid) {
    $s = $pdo->prepare("SELECT * FROM questionnaire_section WHERE questionnaire_id=? ORDER BY order_index ASC");
    $s->execute([$qid]); $sections = $s->fetchAll();
    $i = $pdo->prepare("SELECT * FROM questionnaire_item WHERE questionnaire_id=? ORDER BY order_index ASC");
    $i->execute([$qid]); $items = $i->fetchAll();
}
?>
<!doctype html><html><head>
<meta charset="utf-8"><title><?=t($t,'submit_assessment','Submit Assessment')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/css/material.css">
<link rel="stylesheet" href="assets/css/styles.css">
</head><body class="md-bg">
<?php include __DIR__.'/templates/header.php'; ?>
<section class="md-section">
<div class="md-card md-elev-2">
  <h2 class="md-card-title"><?=t($t,'submit_assessment','Submit Assessment')?></h2>
  <?php if (!empty($err)): ?><div class="md-alert"><?=$err?></div><?php endif; ?>
  <form method="get" class="md-inline-form">
    <label class="md-field">
      <span><?=t($t,'select_questionnaire','Select questionnaire')?></span>
      <select name="qid" onchange="this.form.submit()">
        <?php foreach ($q as $row): ?>
          <option value="<?=$row['id']?>" <?=($row['id']==$qid?'selected':'')?>><?=htmlspecialchars($row['title'])?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
  <?php if ($qid): ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=csrf_token()?>">
    <input type="hidden" name="qid" value="<?=$qid?>">
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