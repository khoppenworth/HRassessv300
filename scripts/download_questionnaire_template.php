<?php
require_once __DIR__ . '/../config.php';

$templatePath = base_path('docs/questionnaire-template.xml');
if (!is_file($templatePath)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Questionnaire template is currently unavailable. Please upload the XML template to docs/questionnaire-template.xml.';
    exit;
}

$data = file_get_contents($templatePath);
if ($data === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unable to read the questionnaire template.';
    exit;
}

header('Content-Type: application/xml');
header('Content-Disposition: attachment; filename="questionnaire_template.xml"');
header('Content-Length: ' . strlen($data));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

echo $data;
exit;
