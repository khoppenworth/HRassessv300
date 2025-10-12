#!/usr/bin/env php
<?php
declare(strict_types=1);

$basePath = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$uploadDir = $basePath . '/assets/uploads/branding';
$uploadDirExists = is_dir($uploadDir);
$uploadDirWritable = $uploadDirExists && is_writable($uploadDir);

$info = [
    'BASE_PATH' => $basePath,
    'BASE_URL' => rtrim((string)(getenv('BASE_URL') ?: '/'), '/') ?: '/',
    'Upload directory' => $uploadDir,
    'Upload directory exists' => $uploadDirExists ? 'yes' : 'no',
    'Upload directory writable' => $uploadDirWritable ? 'yes' : 'no',
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'file_uploads' => ini_get('file_uploads'),
    'fileinfo extension' => extension_loaded('fileinfo') ? 'enabled' : 'missing',
];

foreach ($info as $label => $value) {
    echo str_pad($label . ':', 28) . $value . PHP_EOL;
}

if (!$uploadDirExists) {
    echo "\nHint: create the uploads directory with:\n";
    echo '  mkdir -p ' . $uploadDir . PHP_EOL;
    echo '  chmod 775 ' . $uploadDir . PHP_EOL;
}

if ($uploadDirExists && !$uploadDirWritable) {
    echo "\nWarning: the upload directory is not writable by the current user." . PHP_EOL;
}
