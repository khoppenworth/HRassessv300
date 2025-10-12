<?php
require_once __DIR__ . '/../config.php';
auth_required(['staff', 'supervisor', 'admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$user = current_user();
$targetUserId = (int) ($user['id'] ?? 0);

if ($user['role'] === 'admin' && isset($_GET['user'])) {
    $candidate = (int) $_GET['user'];
    if ($candidate > 0) {
        $targetUserId = $candidate;
    }
}

if ($targetUserId <= 0) {
    http_response_code(400);
    header('Content-Type: image/png');
    $img = imagecreatetruecolor(640, 200);
    $bg = imagecolorallocate($img, 255, 255, 255);
    imagefilledrectangle($img, 0, 0, 640, 200, $bg);
    $text = imagecolorallocate($img, 40, 40, 40);
    $message = t($t, 'no_user_selected', 'No user selected');
    imagestring($img, 4, 160, 90, $message, $text);
    imagepng($img);
    imagedestroy($img);
    exit;
}

$stmt = $pdo->prepare("SELECT qr.score, qr.created_at, pp.label AS period_label
    FROM questionnaire_response qr
    LEFT JOIN performance_period pp ON pp.id = qr.performance_period_id
    WHERE qr.user_id = ?
    ORDER BY qr.created_at ASC");
$stmt->execute([$targetUserId]);
$rows = $stmt->fetchAll();

if (!$rows) {
    header('Content-Type: image/png');
    $img = imagecreatetruecolor(640, 220);
    $bg = imagecolorallocate($img, 244, 247, 251);
    imagefilledrectangle($img, 0, 0, 640, 220, $bg);
    $textColor = imagecolorallocate($img, 33, 61, 98);
    $message = t($t, 'no_trend_data', 'Submit assessments to generate your performance trend.');
    $wrapped = [];
    $words = preg_split('/\s+/', $message) ?: [];
    $line = '';
    foreach ($words as $word) {
        $next = $line === '' ? $word : $line . ' ' . $word;
        if (strlen($next) > 38) {
            $wrapped[] = $line;
            $line = $word;
        } else {
            $line = $next;
        }
    }
    if ($line !== '') {
        $wrapped[] = $line;
    }
    if (!$wrapped) {
        $wrapped = [$message];
    }
    $startY = (int) (110 - (count($wrapped) * 7));
    foreach ($wrapped as $offset => $lineText) {
        $textWidth = imagefontwidth(4) * strlen($lineText);
        $x = (int) ((640 - $textWidth) / 2);
        imagestring($img, 4, max(20, $x), $startY + $offset * 18, $lineText, $textColor);
    }
    imagepng($img);
    imagedestroy($img);
    exit;
}

require_once __DIR__ . '/../lib/jpgraph-lite.php';

$labels = [];
$dataPoints = [];
foreach ($rows as $row) {
    $timestamp = strtotime((string) ($row['created_at'] ?? 'now'));
    $dateLabel = $timestamp ? date('M j, Y', $timestamp) : (string) ($row['created_at'] ?? '');
    $periodLabel = trim((string) ($row['period_label'] ?? ''));
    $labels[] = $periodLabel !== '' ? $dateLabel . ' · ' . $periodLabel : $dateLabel;
    $dataPoints[] = $row['score'] !== null ? (float) $row['score'] : null;
}

try {
    $graph = new \MiniJpGraph\Graph(820, 340);
} catch (\RuntimeException $e) {
    header('Content-Type: image/png');
    $img = imagecreatetruecolor(640, 220);
    $bg = imagecolorallocate($img, 255, 255, 255);
    imagefilledrectangle($img, 0, 0, 640, 220, $bg);
    $textColor = imagecolorallocate($img, 60, 60, 60);
    imagestring($img, 4, 40, 100, 'Charts unavailable: ' . $e->getMessage(), $textColor);
    imagepng($img);
    imagedestroy($img);
    exit;
}
$graph->SetScale('textlin');
$graph->SetMargin(80, 40, 70, 90);
$graph->SetMarginColor('#ffffff');
$graph->SetTitle(t($t, 'performance_timeline_title', 'Performance timeline'));
$graph->xaxis->SetTickLabels($labels);
$graph->xaxis->SetTitle(t($t, 'performance_period', 'Performance Period'));
$graph->yaxis->SetTitle(t($t, 'score_percentage', 'Score (%)'));

$plot = new \MiniJpGraph\LinePlot($dataPoints);
$plot->SetColor(site_brand_color($cfg));
$plot->SetWeight(3);
$graph->Add($plot);

header('Content-Type: image/png');
header('Cache-Control: no-store, private, max-age=0');
$graph->Stroke();
exit;
