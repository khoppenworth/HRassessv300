<?php

declare(strict_types=1);

require_once __DIR__ . '/simple_pdf.php';

function analytics_report_allowed_frequencies(): array
{
    return ['daily', 'weekly', 'monthly'];
}

function analytics_report_frequency_label(string $frequency): string
{
    return match ($frequency) {
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        default => ucfirst($frequency),
    };
}

function analytics_report_parse_recipients(string $input): array
{
    $pieces = preg_split('/[\s,;]+/', trim($input));
    $emails = [];
    foreach ($pieces as $piece) {
        if ($piece === '') {
            continue;
        }
        $candidate = filter_var($piece, FILTER_VALIDATE_EMAIL);
        if ($candidate) {
            $emails[strtolower($candidate)] = $candidate;
        }
    }
    return array_values($emails);
}

function analytics_report_snapshot(PDO $pdo, ?int $questionnaireId = null, bool $includeDetails = false): array
{
    $summaryRow = $pdo->query(
        "SELECT COUNT(*) AS total_responses, "
        . "SUM(status='approved') AS approved_count, "
        . "SUM(status='submitted') AS submitted_count, "
        . "SUM(status='draft') AS draft_count, "
        . "SUM(status='rejected') AS rejected_count, "
        . "AVG(score) AS avg_score, "
        . "MAX(created_at) AS latest_at "
        . "FROM questionnaire_response"
    );
    $summary = [
        'total_responses' => 0,
        'approved_count' => 0,
        'submitted_count' => 0,
        'draft_count' => 0,
        'rejected_count' => 0,
        'avg_score' => null,
        'latest_at' => null,
    ];
    if ($summaryRow) {
        $row = $summaryRow->fetch(PDO::FETCH_ASSOC) ?: [];
        $summary['total_responses'] = (int)($row['total_responses'] ?? 0);
        $summary['approved_count'] = (int)($row['approved_count'] ?? 0);
        $summary['submitted_count'] = (int)($row['submitted_count'] ?? 0);
        $summary['draft_count'] = (int)($row['draft_count'] ?? 0);
        $summary['rejected_count'] = (int)($row['rejected_count'] ?? 0);
        $summary['avg_score'] = isset($row['avg_score']) ? (float)$row['avg_score'] : null;
        $summary['latest_at'] = $row['latest_at'] ?? null;
    }

    $totalParticipants = (int)($pdo->query('SELECT COUNT(DISTINCT user_id) FROM questionnaire_response')->fetchColumn() ?: 0);

    $questionnaireStmt = $pdo->query(
        "SELECT q.id, q.title, COUNT(*) AS total_responses, "
        . "SUM(qr.status='approved') AS approved_count, "
        . "SUM(qr.status='submitted') AS submitted_count, "
        . "SUM(qr.status='draft') AS draft_count, "
        . "SUM(qr.status='rejected') AS rejected_count, "
        . "AVG(qr.score) AS avg_score "
        . "FROM questionnaire_response qr "
        . "JOIN questionnaire q ON q.id = qr.questionnaire_id "
        . "GROUP BY q.id, q.title "
        . "ORDER BY q.title"
    );
    $questionnaires = [];
    $availableIds = [];
    if ($questionnaireStmt) {
        foreach ($questionnaireStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)($row['id'] ?? 0);
            $availableIds[] = $id;
            $questionnaires[] = [
                'id' => $id,
                'title' => (string)($row['title'] ?? ''),
                'total_responses' => (int)($row['total_responses'] ?? 0),
                'approved_count' => (int)($row['approved_count'] ?? 0),
                'submitted_count' => (int)($row['submitted_count'] ?? 0),
                'draft_count' => (int)($row['draft_count'] ?? 0),
                'rejected_count' => (int)($row['rejected_count'] ?? 0),
                'avg_score' => isset($row['avg_score']) ? (float)$row['avg_score'] : null,
            ];
        }
    }

    $selectedId = null;
    if ($questionnaireId && in_array($questionnaireId, $availableIds, true)) {
        $selectedId = $questionnaireId;
    } elseif ($availableIds) {
        $selectedId = $availableIds[0];
    }

    $selectedTitle = '';
    foreach ($questionnaires as $qRow) {
        if ($qRow['id'] === $selectedId) {
            $selectedTitle = (string)$qRow['title'];
            break;
        }
    }

    $userBreakdown = [];
    if ($includeDetails && $selectedId) {
        $userStmt = $pdo->prepare(
            'SELECT u.username, u.full_name, u.work_function, '
            . 'COUNT(*) AS total_responses, '
            . 'SUM(qr.status="approved") AS approved_count, '
            . 'AVG(qr.score) AS avg_score '
            . 'FROM questionnaire_response qr '
            . 'JOIN users u ON u.id = qr.user_id '
            . 'WHERE qr.questionnaire_id = ? '
            . 'GROUP BY u.id, u.username, u.full_name, u.work_function '
            . 'ORDER BY (avg_score IS NULL), avg_score DESC, total_responses DESC '
            . 'LIMIT 15'
        );
        if ($userStmt) {
            $userStmt->execute([$selectedId]);
            $userBreakdown = $userStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    return [
        'summary' => $summary,
        'total_participants' => $totalParticipants,
        'questionnaires' => $questionnaires,
        'selected_questionnaire_id' => $selectedId,
        'selected_questionnaire_title' => $selectedTitle,
        'user_breakdown' => $userBreakdown,
        'include_details' => $includeDetails,
        'generated_at' => new DateTimeImmutable('now'),
    ];
}

function analytics_report_render_pdf(array $snapshot, array $cfg): string
{
    $pdf = new SimplePdfDocument();
    $siteName = trim((string)($cfg['site_name'] ?? ''));
    $title = ($siteName !== '' ? $siteName : 'HR Assessment') . ' Analytics Report';
    $pdf->addHeading($title);

    /** @var DateTimeImmutable $generatedAt */
    $generatedAt = $snapshot['generated_at'];
    $pdf->addParagraph('Generated on ' . $generatedAt->format('Y-m-d H:i'));

    $summary = $snapshot['summary'];
    $summaryRows = [
        ['Total responses', analytics_report_format_number($summary['total_responses'] ?? 0)],
        ['Approved', analytics_report_format_number($summary['approved_count'] ?? 0)],
        ['Submitted', analytics_report_format_number($summary['submitted_count'] ?? 0)],
        ['Draft', analytics_report_format_number($summary['draft_count'] ?? 0)],
        ['Rejected', analytics_report_format_number($summary['rejected_count'] ?? 0)],
        ['Average score', analytics_report_format_score($summary['avg_score'])],
        ['Latest submission', analytics_report_format_date($summary['latest_at'])],
        ['Unique participants', analytics_report_format_number($snapshot['total_participants'] ?? 0)],
    ];

    $pdf->addSubheading('Overall summary');
    $pdf->addTable(['Metric', 'Value'], $summaryRows, [32, 18]);

    $pdf->addSubheading('Questionnaire performance');
    $questionnaireRows = [];
    foreach ($snapshot['questionnaires'] as $row) {
        $questionnaireRows[] = [
            (string)($row['title'] ?? 'Questionnaire'),
            analytics_report_format_number($row['total_responses'] ?? 0),
            analytics_report_format_number($row['approved_count'] ?? 0),
            analytics_report_format_number($row['submitted_count'] ?? 0),
            analytics_report_format_number($row['draft_count'] ?? 0),
            analytics_report_format_number($row['rejected_count'] ?? 0),
            analytics_report_format_score($row['avg_score'] ?? null),
        ];
    }

    if ($questionnaireRows) {
        $pdf->addTable(
            ['Questionnaire', 'Total', 'Approved', 'Submitted', 'Draft', 'Rejected', 'Avg'],
            $questionnaireRows,
            [40, 7, 9, 10, 8, 9, 7]
        );
    } else {
        $pdf->addParagraph('No questionnaire responses have been recorded yet.');
    }

    if (!empty($snapshot['include_details']) && !empty($snapshot['user_breakdown'])) {
        $selectedTitle = trim((string)($snapshot['selected_questionnaire_title'] ?? ''));
        $pdf->addSubheading('Top contributors' . ($selectedTitle !== '' ? ': ' . $selectedTitle : ''));
        $detailRows = [];
        foreach ($snapshot['user_breakdown'] as $row) {
            $display = trim((string)($row['full_name'] ?? ''));
            if ($display === '') {
                $display = (string)($row['username'] ?? 'User');
            }
            $detailRows[] = [
                $display,
                (string)($row['work_function'] ?? ''),
                analytics_report_format_number($row['total_responses'] ?? 0),
                analytics_report_format_number($row['approved_count'] ?? 0),
                analytics_report_format_score($row['avg_score'] ?? null),
            ];
        }
        $pdf->addTable(
            ['User', 'Work function', 'Responses', 'Approved', 'Avg score'],
            $detailRows,
            [28, 18, 12, 10, 10]
        );
    }

    return $pdf->output();
}

function analytics_report_format_number($value): string
{
    $number = (int)$value;
    return number_format($number);
}

function analytics_report_format_score($value): string
{
    if ($value === null) {
        return '-';
    }
    $float = (float)$value;
    return number_format($float, 2);
}

function analytics_report_format_date($value): string
{
    if (!$value) {
        return '-';
    }
    try {
        $dt = new DateTimeImmutable((string)$value);
        return $dt->format('Y-m-d H:i');
    } catch (Throwable $e) {
        return (string)$value;
    }
}

function analytics_report_filename(?int $questionnaireId, DateTimeInterface $generatedAt): string
{
    $suffix = $questionnaireId ? '-q' . $questionnaireId : '';
    return 'analytics-report' . $suffix . '-' . $generatedAt->format('Ymd_His') . '.pdf';
}

function analytics_report_next_run(string $frequency, DateTimeImmutable $from): DateTimeImmutable
{
    return match ($frequency) {
        'daily' => $from->add(new DateInterval('P1D')),
        'monthly' => $from->add(new DateInterval('P1M')),
        default => $from->add(new DateInterval('P7D')),
    };
}
