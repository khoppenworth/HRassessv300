<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/upgrade.php';

auth_required(['admin']);

$filename = trim((string)($_GET['file'] ?? ''));
if ($filename === '') {
    http_response_code(400);
    echo 'Invalid backup request.';
    exit;
}

$resolvedPath = upgrade_resolve_manual_backup_path($filename);
if ($resolvedPath === null || !is_file($resolvedPath)) {
    http_response_code(404);
    echo 'Backup not found.';
    exit;
}

try {
    upgrade_stream_download($resolvedPath, $filename);
} catch (Throwable $e) {
    error_log('Manual backup download failed: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo 'Unable to download the requested backup file.';
}
