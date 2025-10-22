#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only be executed from the command line." . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/upgrade.php';

$options = getopt('', [
    'action:',
    'repo::',
    'ref::',
    'latest-release',
    'preserve::',
    'backup-id::',
    'restore-db',
]);

$action = isset($options['action']) ? strtolower((string)$options['action']) : '';

switch ($action) {
    case 'upgrade':
        runUpgrade(upgrade_engine(), $pdo, $options);
        break;
    case 'downgrade':
    case 'restore':
        runRestore(upgrade_engine(), $pdo, $options);
        break;
    case 'list-backups':
        runList(upgrade_engine());
        break;
    default:
        printUsage();
        exit($action === '' ? 0 : 1);
}

function runUpgrade(UpgradeEngine $engine, PDO $pdo, array $options): void
{
    $repo = upgrade_normalize_source((string)($options['repo'] ?? ''));
    if ($repo === '') {
        $cfg = get_site_config($pdo);
        $repo = upgrade_effective_source($cfg);
    }

    if (!upgrade_is_valid_source($repo)) {
        fwrite(STDERR, 'Enter a valid release source such as owner/repository or an HTTPS Git URL.' . PHP_EOL);
        exit(1);
    }

    $latest = isset($options['latest-release']);
    $reference = trim((string)($options['ref'] ?? ''));

    $token = trim((string)(getenv('GITHUB_TOKEN') ?: ''));
    $releaseUrl = null;
    if ($latest) {
        $release = $engine->fetchLatestRelease($repo, $token !== '' ? $token : null);
        if ($release === null) {
            fwrite(STDERR, 'Unable to determine the latest release for ' . $repo . PHP_EOL);
            exit(1);
        }
        $targetRef = (string)$release['tag'];
        $targetLabel = (string)($release['name'] ?? $targetRef);
        $releaseUrl = isset($release['url']) ? (string)$release['url'] : null;
    } else {
        $targetRef = $reference !== '' ? $reference : 'main';
        $targetLabel = $targetRef;
    }

    $preserveInput = (string)($options['preserve'] ?? '');
    $extraPreserve = array_filter(array_map('trim', explode(',', $preserveInput)));
    $preservePaths = array_values(array_unique(array_merge([
        'config.php',
        'assets/uploads',
        'storage',
        'storage/upgrades',
    ], $extraPreserve)));

    $run = $engine->beginRun('upgrade', [
        'repo' => $repo,
        'ref' => $targetRef,
        'version_label' => $targetLabel,
        'release_url' => $releaseUrl,
        'preserve' => $preservePaths,
    ]);

    $applicationArchive = $run['dir'] . DIRECTORY_SEPARATOR . 'application.zip';
    $databaseDump = $run['dir'] . DIRECTORY_SEPARATOR . 'database.sql';
    $tempDir = null;

    info('Starting upgrade run ' . $run['id']);
    info('Source repository: ' . $repo . ' @ ' . $targetRef);

    try {
        info('Creating application snapshot...');
        $engine->createApplicationArchive($applicationArchive, $preservePaths);
        $engine->updateRun($run, [
            'app_snapshot' => $engine->relativeToRoot($applicationArchive),
        ]);
        info('Snapshot stored at ' . $engine->relativeToRoot($applicationArchive));

        info('Exporting database...');
        $engine->createDatabaseDumpFile($pdo, $databaseDump);
        $engine->updateRun($run, [
            'db_backup' => $engine->relativeToRoot($databaseDump),
        ]);
        info('Database dump stored at ' . $engine->relativeToRoot($databaseDump));

        info('Fetching release package...');
        $download = $engine->downloadReleaseArchive($repo, $targetRef, $token !== '' ? $token : null);
        $tempDir = $download['tmp_dir'];
        $engine->updateRun($run, [
            'package_path' => $engine->relativeToRoot($download['archive']),
        ]);
        info('Package downloaded. Applying files...');
        $engine->deployRelease($download['extract_root'], $preservePaths);
        info('Release deployed successfully.');

        $engine->completeRun($run, 'success');
        upgrade_store_installed_release([
            'tag' => $targetRef,
            'name' => $targetLabel,
            'repo' => $repo,
            'url' => $releaseUrl,
            'installed_at' => date(DATE_ATOM),
        ]);

        info('Upgrade completed successfully.');
    } catch (Throwable $e) {
        $engine->completeRun($run, 'failed', $e->getMessage());
        fwrite(STDERR, 'Upgrade failed: ' . $e->getMessage() . PHP_EOL);
        fwrite(STDERR, 'Application snapshot: ' . $engine->relativeToRoot($applicationArchive) . PHP_EOL);
        fwrite(STDERR, 'Database backup: ' . $engine->relativeToRoot($databaseDump) . PHP_EOL);
        exit(1);
    } finally {
        if ($tempDir !== null) {
            $engine->removeDirectory($tempDir);
        }
    }
}

function runRestore(UpgradeEngine $engine, PDO $pdo, array $options): void
{
    $runs = $engine->listUpgradeRuns();
    if ($runs === []) {
        fwrite(STDERR, 'No upgrade backups are available to restore.' . PHP_EOL);
        exit(1);
    }

    $backupId = trim((string)($options['backup-id'] ?? ''));
    $restoreDb = isset($options['restore-db']);

    $target = null;
    if ($backupId !== '') {
        foreach ($runs as $run) {
            $runId = (string)($run['id'] ?? ($run['timestamp'] ?? ''));
            if ($runId === $backupId) {
                $target = $run;
                break;
            }
        }
        if ($target === null) {
            fwrite(STDERR, 'Backup ' . $backupId . ' was not found.' . PHP_EOL);
            exit(1);
        }
    } else {
        foreach ($runs as $run) {
            if (($run['status'] ?? '') === 'success') {
                $target = $run;
                break;
            }
        }
        if ($target === null) {
            fwrite(STDERR, 'No successful upgrade runs are available to restore.' . PHP_EOL);
            exit(1);
        }
    }

    $snapshotRelative = (string)($target['app_snapshot'] ?? '');
    if ($snapshotRelative === '') {
        fwrite(STDERR, 'The selected backup does not include an application snapshot.' . PHP_EOL);
        exit(1);
    }

    $snapshotAbsolute = $engine->absoluteFromRoot($snapshotRelative);
    info('Restoring application snapshot from ' . $snapshotRelative . '...');
    $engine->restoreApplicationSnapshot($snapshotAbsolute, [
        'config.php',
        'assets/uploads',
        'storage',
        'storage/upgrades',
    ]);
    info('Application files restored.');

    if ($restoreDb) {
        $dbRelative = (string)($target['db_backup'] ?? '');
        if ($dbRelative === '') {
            fwrite(STDERR, 'The selected backup does not include a database dump.' . PHP_EOL);
            exit(1);
        }
        $dbAbsolute = $engine->absoluteFromRoot($dbRelative);
        info('Restoring database from ' . $dbRelative . '...');
        $engine->restoreDatabaseFromDump($pdo, $dbAbsolute);
        info('Database restored successfully.');
    } else {
        info('Database restore skipped. Use --restore-db to enable it.');
    }

    info('Restore operation completed.');
}

function runList(UpgradeEngine $engine): void
{
    $runs = $engine->listUpgradeRuns();
    if ($runs === []) {
        echo 'No upgrade history found.' . PHP_EOL;
        return;
    }

    printf("%-18s %-12s %-25s %-30s %s\n", 'Backup ID', 'Status', 'Reference', 'Created At', 'Manifest');
    echo str_repeat('-', 110) . PHP_EOL;
    foreach ($runs as $run) {
        $id = (string)($run['id'] ?? ($run['timestamp'] ?? ''));
        $status = (string)($run['status'] ?? 'unknown');
        $ref = (string)($run['ref'] ?? '');
        $created = (string)($run['created_at'] ?? '');
        $manifest = (string)($run['manifest_path'] ?? '');
        printf("%-18s %-12s %-25s %-30s %s\n", $id, $status, $ref, $created, $manifest);
    }
}

function printUsage(): void
{
    $script = basename(__FILE__);
    echo <<<USAGE
System upgrade utility
Usage:
  php scripts/{$script} --action=upgrade [--repo=<repository>] [--ref=<branch|tag>] [--latest-release] [--preserve=<paths>]
  php scripts/{$script} --action=downgrade [--backup-id=<id>] [--restore-db]
  php scripts/{$script} --action=list-backups

Options:
  --repo             Repository slug or URL used to download releases. Defaults to the configured upgrade source.
  --ref              Branch, tag, or commit reference to deploy when --latest-release is not supplied.
  --latest-release   Resolve the newest GitHub release tag automatically.
  --preserve         Comma separated list of paths to keep untouched during deployment.
  --backup-id        Identifier of the backup run to restore. Defaults to the most recent successful run.
  --restore-db       Restore the database dump alongside application files.

USAGE;
}

function info(string $message): void
{
    fwrite(STDOUT, '[INFO] ' . $message . PHP_EOL);
}
