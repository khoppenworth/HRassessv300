<?php
require_once __DIR__.'/../config.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="responses.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, [
  'response_id',
  'username',
  'full_name',
  'email',
  'role',
  'work_function',
  'account_status',
  'questionnaire_id',
  'questionnaire_title',
  'status',
  'score_percent',
  'performance_period',
  'created_at',
  'reviewed_at',
  'reviewer_username',
  'reviewer_full_name',
  'review_comment',
]);
$sql = "SELECT qr.id, u.username, u.full_name, u.email, u.role, u.work_function, u.account_status, qr.questionnaire_id, q.title AS questionnaire_title, qr.status, qr.score, qr.created_at, qr.reviewed_at, reviewer.username AS reviewer_username, reviewer.full_name AS reviewer_full_name, qr.review_comment, pp.label AS period_label FROM questionnaire_response qr JOIN users u ON u.id=qr.user_id LEFT JOIN questionnaire q ON q.id = qr.questionnaire_id LEFT JOIN users reviewer ON reviewer.id = qr.reviewed_by LEFT JOIN performance_period pp ON pp.id = qr.performance_period_id ORDER BY qr.id DESC";
foreach ($pdo->query($sql) as $r) {
  fputcsv($out, [
    $r['id'],
    $r['username'],
    $r['full_name'],
    $r['email'],
    $r['role'],
    $r['work_function'],
    $r['account_status'],
    $r['questionnaire_id'],
    $r['questionnaire_title'],
    $r['status'],
    $r['score'],
    $r['period_label'],
    $r['created_at'],
    $r['reviewed_at'],
    $r['reviewer_username'],
    $r['reviewer_full_name'],
    $r['review_comment'],
  ]);
}
fclose($out);
exit;