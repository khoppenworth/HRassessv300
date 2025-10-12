<?php
declare(strict_types=1);

/**
 * Create a full application backup archive that mirrors the admin UI download.
 *
 * @param PDO    $pdo         Database connection used for metadata exports.
 * @param array  $cfg         Cached site configuration for summary details.
 * @param string $destination Absolute path to the zip archive that will be written.
 * @param array  $options     Optional overrides: app_root, uploads_dir, skip_dirs.
 *
 * @return int Number of files written into the archive.
 */
function create_system_backup_archive(PDO $pdo, array $cfg, string $destination, array $options = []): int
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The ZipArchive extension is required to generate backups.');
    }

    $appRoot = $options['app_root'] ?? base_path('');
    $uploadsDir = $options['uploads_dir'] ?? base_path('assets/uploads');
    $skipDirs = $options['skip_dirs'] ?? [dirname($destination)];

    $skipRealPaths = [];
    foreach ($skipDirs as $dir) {
        $real = $dir !== null ? realpath($dir) : false;
        if ($real !== false) {
            $skipRealPaths[] = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $real), DIRECTORY_SEPARATOR);
        }
    }

    $zip = new ZipArchive();
    if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create backup archive at ' . $destination);
    }

    $filesAdded = 0;
    $addString = static function (ZipArchive $archive, string $path, string $contents) use (&$filesAdded): void {
        if ($archive->addFromString($path, $contents) !== true) {
            throw new RuntimeException('Failed to write "' . $path . '" into the backup archive.');
        }
        $filesAdded++;
    };

    $summaryLines = [
        'System backup created on ' . date('c'),
        'Site: ' . ($cfg['site_name'] ?? 'My Performance'),
        'Users: ' . backup_fetch_count($pdo, 'SELECT COUNT(*) c FROM users'),
        'Assessments: ' . backup_fetch_count($pdo, 'SELECT COUNT(*) c FROM questionnaire_response'),
        'Draft responses: ' . backup_fetch_count($pdo, "SELECT COUNT(*) c FROM questionnaire_response WHERE status='draft'"),
    ];
    $addString($zip, 'summary.txt', implode("\n", $summaryLines) . "\n");

    foreach (backup_data_sets($pdo) as $archivePath => $rows) {
        $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Failed to encode dataset for ' . $archivePath . ': ' . json_last_error_msg());
        }
        $addString($zip, $archivePath, $json . "\n");
    }

    $dump = backup_generate_database_dump($pdo);
    if ($dump !== null) {
        $addString($zip, 'database/backup.sql', $dump);
    }

    backup_add_directory($zip, $uploadsDir, 'uploads', $skipRealPaths, $filesAdded);
    backup_add_directory($zip, $appRoot, 'application', $skipRealPaths, $filesAdded, ['.git/']);

    if ($zip->close() !== true) {
        throw new RuntimeException('Failed to finalize the backup archive.');
    }

    clearstatcache(true, $destination);
    if (!is_file($destination) || filesize($destination) <= 0 || $filesAdded === 0) {
        throw new RuntimeException('Backup archive was created but did not contain any files.');
    }

    return $filesAdded;
}

function backup_fetch_count(PDO $pdo, string $sql): int
{
    try {
        $stmt = $pdo->query($sql);
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($row === null) {
            return 0;
        }
        if (array_key_exists('c', $row)) {
            return (int)$row['c'];
        }
        $values = array_values($row);
        return isset($values[0]) ? (int)$values[0] : 0;
    } catch (PDOException $e) {
        error_log('Backup metric failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * @return array<string, array<int, array<string, mixed>>>
 */
function backup_data_sets(PDO $pdo): array
{
    $datasets = [];

    $datasets['data/site_config.json'] = backup_fetch_rows($pdo, 'SELECT * FROM site_config ORDER BY id');
    $datasets['data/users.json'] = backup_fetch_rows(
        $pdo,
        'SELECT id, username, role, full_name, email, work_function, account_status, next_assessment_date, first_login_at, created_at FROM users ORDER BY id'
    );
    $datasets['data/questionnaires.json'] = backup_fetch_rows(
        $pdo,
        'SELECT id, title, description, created_at FROM questionnaire ORDER BY id'
    );
    $datasets['data/questionnaire_items.json'] = backup_fetch_rows(
        $pdo,
        'SELECT id, questionnaire_id, section_id, linkId, text, type, order_index, weight_percent, allow_multiple FROM questionnaire_item ORDER BY questionnaire_id, order_index'
    );
    $datasets['data/questionnaire_responses.json'] = backup_fetch_rows(
        $pdo,
        'SELECT id, user_id, questionnaire_id, performance_period_id, status, score, reviewed_by, reviewed_at, review_comment, created_at FROM questionnaire_response ORDER BY id'
    );
    $datasets['data/questionnaire_response_items.json'] = backup_fetch_rows(
        $pdo,
        'SELECT response_id, linkId, answer FROM questionnaire_response_item ORDER BY response_id, id'
    );

    return $datasets;
}

/**
 * @return array<int, array<string, mixed>>
 */
function backup_fetch_rows(PDO $pdo, string $sql): array
{
    try {
        $stmt = $pdo->query($sql);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (PDOException $e) {
        error_log('Backup dataset query failed: ' . $e->getMessage() . ' SQL: ' . $sql);
        return [];
    }
}

function backup_generate_database_dump(PDO $pdo): ?string
{
    try {
        $tablesStmt = $pdo->query('SHOW TABLES');
        $tables = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $dumpLines = [];
        $dumpLines[] = '-- Database backup generated ' . date('c');

        foreach ($tables as $tableName) {
            $table = (string)$tableName;
            if ($table === '') {
                continue;
            }
            $safeTable = str_replace('`', '``', $table);
            $dumpLines[] = '';
            $dumpLines[] = 'DROP TABLE IF EXISTS `' . $safeTable . '`;';
            $createStmt = $pdo->query('SHOW CREATE TABLE `' . $safeTable . '`');
            $createRow = $createStmt ? $createStmt->fetch(PDO::FETCH_ASSOC) : null;
            if ($createRow) {
                $createSql = $createRow['Create Table'] ?? ($createRow['Create View'] ?? null);
                if ($createSql) {
                    $dumpLines[] = $createSql . ';';
                }
            }
            $dataStmt = $pdo->query('SELECT * FROM `' . $safeTable . '`');
            if ($dataStmt) {
                while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
                    $columns = array_map(static function ($column) {
                        return '`' . str_replace('`', '``', (string)$column) . '`';
                    }, array_keys($row));
                    $values = array_map(static function ($value) use ($pdo) {
                        if ($value === null) {
                            return 'NULL';
                        }
                        return $pdo->quote((string)$value);
                    }, array_values($row));
                    $dumpLines[] = sprintf(
                        'INSERT INTO `%s` (%s) VALUES (%s);',
                        $safeTable,
                        implode(', ', $columns),
                        implode(', ', $values)
                    );
                }
            }
        }

        return implode("\n", $dumpLines) . "\n";
    } catch (Throwable $dumpError) {
        error_log('Database backup export failed: ' . $dumpError->getMessage());
        return null;
    }
}

/**
 * @param array<int, string> $skipRealPaths
 * @param array<int, string> $skipRelativePrefixes
 */
function backup_add_directory(
    ZipArchive $zip,
    string $sourceDir,
    string $targetPrefix,
    array $skipRealPaths,
    int &$filesAdded,
    array $skipRelativePrefixes = []
): void {
    if ($sourceDir === '' || !is_dir($sourceDir)) {
        return;
    }

    $rootReal = realpath($sourceDir);
    if ($rootReal === false) {
        return;
    }
    $normalizedRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rootReal), DIRECTORY_SEPARATOR);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $pathName = $fileInfo->getPathname();
        $realPath = $fileInfo->getRealPath();
        $normalizedReal = $realPath !== false
            ? rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $realPath), DIRECTORY_SEPARATOR)
            : rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $pathName), DIRECTORY_SEPARATOR);

        foreach ($skipRealPaths as $skipPath) {
            if ($skipPath === '') {
                continue;
            }
            $prefix = $skipPath . DIRECTORY_SEPARATOR;
            if ($normalizedReal === $skipPath || strpos($normalizedReal . DIRECTORY_SEPARATOR, $prefix) === 0) {
                continue 2;
            }
        }

        $relative = substr($normalizedReal, strlen($normalizedRoot) + 1);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        foreach ($skipRelativePrefixes as $prefix) {
            $cleanPrefix = ltrim($prefix, '/');
            if ($cleanPrefix !== '' && strpos($relative, $cleanPrefix) === 0) {
                continue 2;
            }
        }

        $archivePath = rtrim($targetPrefix, '/') . '/' . $relative;
        if ($zip->addFile($pathName, $archivePath) !== true) {
            throw new RuntimeException('Failed to add "' . $pathName . '" to the backup archive.');
        }
        $filesAdded++;
    }
}
