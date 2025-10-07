<?php
require_once __DIR__.'/utils.php';
if ($_SERVER['REQUEST_METHOD']==='GET') {
  $entries = [];
  $rs = $pdo->query("SELECT qr.*, pp.label AS period_label FROM questionnaire_response qr JOIN performance_period pp ON pp.id = qr.performance_period_id ORDER BY qr.id DESC");
  foreach ($rs as $r) {
    $items = $pdo->prepare("SELECT linkId, answer FROM questionnaire_response_item WHERE response_id=?");
    $items->execute([$r['id']]);
    $entries[] = ["resource"=>[
      "resourceType"=>"QuestionnaireResponse",
      "id"=>$r['id'],
      "questionnaire"=>$r['questionnaire_id'],
      "status"=>$r['status'],
      "authored"=>$r['created_at'],
      "performancePeriod"=>$r['period_label'],
      "item"=>array_map(function($it){ return ["linkId"=>$it['linkId'],"answer"=>json_decode($it['answer'], true)]; }, $items->fetchAll())
    ]];
  }
  echo json_encode(bundle($entries)); exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $data = json_decode(file_get_contents('php://input'), true);
  if (($data['resourceType'] ?? '')!=='QuestionnaireResponse') { http_response_code(400); echo json_encode(["error"=>"Invalid resourceType"]); exit; }
  $uid = (int)($data['user_id'] ?? 0);
  $qid = (int)($data['questionnaire'] ?? 0);
  if (!$uid || !$qid) { http_response_code(400); echo json_encode(["error"=>"user_id and questionnaire required"]); exit; }
  $periodId = (int)($data['performance_period_id'] ?? 0);
  if (!$periodId && !empty($data['performance_period'])) {
    $label = (string)$data['performance_period'];
    $lookup = $pdo->prepare('SELECT id FROM performance_period WHERE label = ?');
    $lookup->execute([$label]);
    $periodId = (int)($lookup->fetchColumn() ?: 0);
  }
  if (!$periodId) {
    $current = date('Y');
    $lookup = $pdo->prepare('SELECT id FROM performance_period WHERE label = ?');
    $lookup->execute([$current]);
    $periodId = (int)($lookup->fetchColumn() ?: 0);
  }
  if (!$periodId) {
    http_response_code(400); echo json_encode(["error"=>"performance_period_id required"]); exit;
  }

  $pdo->beginTransaction();
  try {
    $pdo->prepare("INSERT INTO questionnaire_response (user_id, questionnaire_id, performance_period_id, status, created_at) VALUES (?,?,?, 'submitted', NOW())")->execute([$uid,$qid,$periodId]);
    $rid = (int)$pdo->lastInsertId();

    // Calculate weighted score
    $items_meta = $pdo->prepare("SELECT linkId, type, COALESCE(weight_percent,0) AS weight FROM questionnaire_item WHERE questionnaire_id=?");
    $items_meta->execute([$qid]);
    $meta = [];
    foreach ($items_meta as $m) { $meta[$m['linkId']] = $m; }
    $score_sum = 0; $weight_sum = 0;

    foreach (($data['item'] ?? []) as $it) {
      $lid = $it['linkId'] ?? '';
      $ansArr = $it['answer'] ?? [];
      $ans = json_encode($ansArr);
      $pdo->prepare("INSERT INTO questionnaire_response_item (response_id, linkId, answer) VALUES (?,?,?)")->execute([$rid, $lid, $ans]);

      $w = isset($meta[$lid]) ? (float)$meta[$lid]['weight'] : 0.0;
      $weight_sum += $w;
      // Scoring rule: true boolean OR non-empty string → full weight
      foreach ($ansArr as $a) {
        if (isset($a['valueBoolean']) && $a['valueBoolean']===true) { $score_sum += $w; break; }
        if (isset($a['valueString']) && trim((string)$a['valueString'])!=='') { $score_sum += $w; break; }
      }
    }
    $pct = $weight_sum > 0 ? (int)round(($score_sum / $weight_sum) * 100) : null;
    $pdo->prepare("UPDATE questionnaire_response SET score=? WHERE id=?")->execute([$pct, $rid]);
    $pdo->commit();
    echo json_encode(["id"=>$rid, "status"=>"created"]);
  } catch (Exception $e) {
    $pdo->rollBack(); http_response_code(500); echo json_encode(["error"=>$e->getMessage()]);
  }
  exit;
}
http_response_code(405); echo json_encode(["error"=>"Method not allowed"]);
?>