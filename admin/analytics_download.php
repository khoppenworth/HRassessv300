<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/analytics_report.php';

auth_required(['admin', 'supervisor']);
refresh_current_user($pdo);
require_profile_completion($pdo);

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$questionnaireId = null;
if (isset($_GET['questionnaire_id'])) {
    $candidate = (int)$_GET['questionnaire_id'];
    if ($candidate > 0) {
        $questionnaireId = $candidate;
    }
}

$includeDetails = false;
if (isset($_GET['include_details'])) {
    $rawInclude = $_GET['include_details'];
    $includeDetails = $rawInclude === '1'
        || $rawInclude === 'true'
        || $rawInclude === 'on';
}

try {
    $snapshot = analytics_report_snapshot($pdo, $questionnaireId, $includeDetails);
    /** @var DateTimeImmutable $generatedAt */
    $generatedAt = $snapshot['generated_at'];
    $filename = analytics_report_filename($snapshot['selected_questionnaire_id'], $generatedAt);
    $pdfData = analytics_report_render_pdf($snapshot, $cfg);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private');
    header('Content-Length: ' . strlen($pdfData));

    echo $pdfData;
    exit;
} catch (Throwable $e) {
    error_log('analytics report download failed: ' . $e->getMessage());
    $_SESSION['analytics_report_flash'] = [
        'message' => '',
        'error' => t($t, 'analytics_report_download_failed', 'Unable to generate the analytics report. Please try again.'),
    ];
    header('Location: ' . url_for('admin/analytics.php'));
    exit;
}
