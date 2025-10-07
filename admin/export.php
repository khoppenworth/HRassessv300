<?php
require_once __DIR__.'/../config.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="responses.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['response_id','user','questionnaire_id','status','score_percent','performance_period','created_at']);
$sql = "SELECT qr.id, u.username, qr.questionnaire_id, qr.status, qr.score, qr.created_at, pp.label AS period_label FROM questionnaire_response qr JOIN users u ON u.id=qr.user_id JOIN performance_period pp ON pp.id = qr.performance_period_id ORDER BY qr.id DESC";
foreach ($pdo->query($sql) as $r) {
  fputcsv($out, [$r['id'],$r['username'],$r['questionnaire_id'],$r['status'],$r['score'],$r['period_label'],$r['created_at']]);
}
fclose($out);
exit;