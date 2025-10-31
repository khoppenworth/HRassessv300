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

    $workFunctionRows = [];
    try {
        $workFunctionStmt = $pdo->query(
            "SELECT u.work_function, COUNT(*) AS total_responses, "
            . "SUM(qr.status='approved') AS approved_count, "
            . "AVG(qr.score) AS avg_score "
            . "FROM questionnaire_response qr "
            . "JOIN users u ON u.id = qr.user_id "
            . "GROUP BY u.work_function "
            . "ORDER BY total_responses DESC"
        );
        if ($workFunctionStmt) {
            $workFunctionRows = $workFunctionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (PDOException $e) {
        error_log('analytics_report work function summary failed: ' . $e->getMessage());
        $workFunctionRows = [];
    }

    $workFunctionChoices = function_exists('work_function_choices') ? work_function_choices($pdo) : [];
    $workFunctions = [];
    foreach ($workFunctionRows as $row) {
        $key = trim((string)($row['work_function'] ?? ''));
        $label = $workFunctionChoices[$key] ?? ($key !== '' ? $key : 'Unknown');
        $workFunctions[] = [
            'label' => (string)$label,
            'total_responses' => (int)($row['total_responses'] ?? 0),
            'approved_count' => (int)($row['approved_count'] ?? 0),
            'avg_score' => isset($row['avg_score']) ? (float)$row['avg_score'] : null,
        ];
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

    $questionnaireChart = [];
    foreach ($questionnaires as $row) {
        if (!isset($row['avg_score']) || $row['avg_score'] === null) {
            continue;
        }
        $questionnaireChart[] = [
            'label' => (string)($row['title'] ?? 'Questionnaire'),
            'value' => (float)$row['avg_score'],
            'count' => (int)($row['total_responses'] ?? 0),
        ];
    }
    usort($questionnaireChart, static function (array $a, array $b): int {
        $aScore = $a['value'] ?? -INF;
        $bScore = $b['value'] ?? -INF;
        if ($aScore === $bScore) {
            return strcmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
        }
        return $bScore <=> $aScore;
    });
    if (count($questionnaireChart) > 12) {
        $questionnaireChart = array_slice($questionnaireChart, 0, 12);
    }

    $workFunctionChart = [];
    foreach ($workFunctions as $row) {
        if (!isset($row['avg_score']) || $row['avg_score'] === null) {
            continue;
        }
        $workFunctionChart[] = [
            'label' => (string)($row['label'] ?? 'Work function'),
            'value' => (float)$row['avg_score'],
            'count' => (int)($row['total_responses'] ?? 0),
        ];
    }
    usort($workFunctionChart, static function (array $a, array $b): int {
        $aScore = $a['value'] ?? -INF;
        $bScore = $b['value'] ?? -INF;
        if ($aScore === $bScore) {
            return strcmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
        }
        return $bScore <=> $aScore;
    });
    if (count($workFunctionChart) > 12) {
        $workFunctionChart = array_slice($workFunctionChart, 0, 12);
    }

    $periodSeries = analytics_report_collect_period_series($pdo, null);
    $selectedPeriodSeries = $selectedId ? analytics_report_collect_period_series($pdo, $selectedId) : [];

    return [
        'summary' => $summary,
        'total_participants' => $totalParticipants,
        'questionnaires' => $questionnaires,
        'work_functions' => $workFunctions,
        'selected_questionnaire_id' => $selectedId,
        'selected_questionnaire_title' => $selectedTitle,
        'user_breakdown' => $userBreakdown,
        'questionnaire_chart' => $questionnaireChart,
        'work_function_chart' => $workFunctionChart,
        'period_chart' => $periodSeries,
        'period_chart_selected' => $selectedPeriodSeries,
        'include_details' => $includeDetails,
        'generated_at' => new DateTimeImmutable('now'),
    ];
}

function analytics_report_collect_period_series(PDO $pdo, ?int $questionnaireId = null): array
{
    $sql = 'SELECT pp.label, pp.period_start, AVG(qr.score) AS avg_score, COUNT(*) AS total_responses '
        . 'FROM questionnaire_response qr '
        . 'JOIN performance_period pp ON pp.id = qr.performance_period_id '
        . 'WHERE qr.score IS NOT NULL';
    $params = [];
    if ($questionnaireId) {
        $sql .= ' AND qr.questionnaire_id = ?';
        $params[] = $questionnaireId;
    }
    $sql .= ' GROUP BY pp.id, pp.label, pp.period_start ORDER BY pp.period_start ASC';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('analytics_report period series failed: ' . $e->getMessage());
        return [];
    }

    $series = [];
    foreach ($rows as $row) {
        if (!isset($row['avg_score']) || $row['avg_score'] === null) {
            continue;
        }
        $series[] = [
            'label' => (string)($row['label'] ?? ''),
            'value' => (float)$row['avg_score'],
            'count' => (int)($row['total_responses'] ?? 0),
        ];
    }

    return $series;
}

function analytics_report_render_pdf(array $snapshot, array $cfg): string
{
    $pdf = new SimplePdfDocument();
    $siteName = trim((string)($cfg['site_name'] ?? ''));
    $headerTitle = $siteName !== '' ? $siteName : 'HR Assessment';
    $headerSubtitle = analytics_report_header_tagline($cfg);
    $logoSpec = analytics_report_header_logo_spec($pdf, $cfg);
    $pdf->setHeader($headerTitle, $headerSubtitle, $logoSpec);

    $title = $headerTitle . ' Analytics Report';
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

    if (!empty($snapshot['work_functions'])) {
        $workRows = [];
        foreach ($snapshot['work_functions'] as $row) {
            $workRows[] = [
                (string)($row['label'] ?? 'Work function'),
                analytics_report_format_number($row['total_responses'] ?? 0),
                analytics_report_format_number($row['approved_count'] ?? 0),
                analytics_report_format_score($row['avg_score'] ?? null),
            ];
        }
        if ($workRows) {
            $pdf->addSubheading('Performance by work function');
            $pdf->addTable(
                ['Work function', 'Responses', 'Approved', 'Avg'],
                $workRows,
                [40, 14, 14, 10]
            );
        }
    }

    $pdf->addSubheading('Performance charts');
    $chartsAdded = false;
    $palette = analytics_report_palette_colors($cfg);

    $questionnaireChartData = $snapshot['questionnaire_chart'] ?? [];
    $questionnaireChartImage = analytics_report_generate_bar_chart($questionnaireChartData, $palette, [
        'max_value' => 100,
        'value_suffix' => '%',
        'decimal_places' => 1,
    ]);
    if ($questionnaireChartImage) {
        $chartsAdded = true;
        $label = 'Average score by questionnaire';
        if ($questionnaireChartData) {
            $label .= ' (top ' . count($questionnaireChartData) . ')';
        }
        $pdf->addParagraph($label);
        $pdf->addImageBlock(
            $questionnaireChartImage['data'],
            $questionnaireChartImage['width'],
            $questionnaireChartImage['height'],
            520.0
        );
    }

    $workFunctionChartData = $snapshot['work_function_chart'] ?? [];
    $workFunctionChartImage = analytics_report_generate_bar_chart($workFunctionChartData, $palette, [
        'max_value' => 100,
        'value_suffix' => '%',
        'decimal_places' => 1,
    ]);
    if ($workFunctionChartImage) {
        $chartsAdded = true;
        $pdf->addParagraph('Average score by work function');
        $pdf->addImageBlock(
            $workFunctionChartImage['data'],
            $workFunctionChartImage['width'],
            $workFunctionChartImage['height'],
            520.0
        );
    }

    $periodChartData = $snapshot['period_chart'] ?? [];
    $periodChartImage = analytics_report_generate_line_chart($periodChartData, $palette, [
        'max_value' => 100,
        'value_suffix' => '%',
    ]);
    if ($periodChartImage) {
        $chartsAdded = true;
        $pdf->addParagraph('Average score by performance period');
        $pdf->addImageBlock(
            $periodChartImage['data'],
            $periodChartImage['width'],
            $periodChartImage['height'],
            520.0
        );
    }

    $selectedPeriodChart = $snapshot['period_chart_selected'] ?? [];
    if ($selectedPeriodChart && $snapshot['selected_questionnaire_title']) {
        $selectedPeriodImage = analytics_report_generate_line_chart($selectedPeriodChart, $palette, [
            'max_value' => 100,
            'value_suffix' => '%',
        ]);
        if ($selectedPeriodImage) {
            $chartsAdded = true;
            $pdf->addParagraph('Period trend · ' . (string)$snapshot['selected_questionnaire_title']);
            $pdf->addImageBlock(
                $selectedPeriodImage['data'],
                $selectedPeriodImage['width'],
                $selectedPeriodImage['height'],
                520.0
            );
        }
    }

    if (!$chartsAdded) {
        $pdf->addParagraph('Not enough response data is available to generate charts yet.');
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

    $pdf->addSubheading('Sign-off');
    $pdf->addParagraph('Please review this report and acknowledge completion with the signatures below.');
    $pdf->addSignatureFields([
        ['Staff name', 'Staff signature'],
        ['Supervisor name', 'Supervisor signature'],
    ]);

    return $pdf->output();
}

function analytics_report_generate_bar_chart(array $points, array $palette, array $options = []): ?array
{
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }

    $normalized = [];
    foreach ($points as $point) {
        $value = $point['value'] ?? ($point['score'] ?? null);
        if (!is_numeric($value)) {
            continue;
        }
        $normalized[] = [
            'label' => analytics_report_truncate_label((string)($point['label'] ?? '')),
            'value' => (float)$value,
            'count' => isset($point['count']) ? (int)$point['count'] : (int)($point['responses'] ?? 0),
        ];
    }

    if ($normalized === []) {
        return null;
    }

    $width = (int)($options['width'] ?? 1200);
    $height = (int)($options['height'] ?? 640);
    $image = imagecreatetruecolor($width, $height);
    if (!$image) {
        return null;
    }

    if (function_exists('imageantialias')) {
        @imageantialias($image, true);
    }

    $background = imagecolorallocate($image, 255, 255, 255);
    imagefilledrectangle($image, 0, 0, $width, $height, $background);

    $axisColor = imagecolorallocate($image, 148, 163, 184);
    $gridColor = imagecolorallocate($image, 226, 232, 240);
    $textColor = imagecolorallocate($image, 30, 41, 59);
    $plotBackground = imagecolorallocate($image, 248, 250, 252);

    $primaryRgb = analytics_report_color_to_rgb($palette['primary'] ?? '#2563eb');
    $barRgb = $primaryRgb;
    $barShadowRgb = analytics_report_adjust_color($primaryRgb, -0.25);
    $barHighlightRgb = analytics_report_adjust_color($primaryRgb, 0.2);
    $barColor = imagecolorallocate($image, $barRgb[0], $barRgb[1], $barRgb[2]);
    $barShadow = imagecolorallocate($image, $barShadowRgb[0], $barShadowRgb[1], $barShadowRgb[2]);
    $barHighlight = imagecolorallocate($image, $barHighlightRgb[0], $barHighlightRgb[1], $barHighlightRgb[2]);

    $marginLeft = 150;
    $marginRight = 80;
    $marginTop = 90;
    $marginBottom = 160;

    $chartWidth = $width - $marginLeft - $marginRight;
    $chartHeight = $height - $marginTop - $marginBottom;
    if ($chartWidth <= 0 || $chartHeight <= 0) {
        imagedestroy($image);
        return null;
    }

    imagefilledrectangle($image, $marginLeft, $marginTop, $marginLeft + $chartWidth, $marginTop + $chartHeight, $plotBackground);

    $values = array_column($normalized, 'value');
    $maxValue = max($values);
    if (isset($options['max_value']) && is_numeric($options['max_value'])) {
        $maxValue = max($maxValue, (float)$options['max_value']);
    }
    if ($maxValue <= 0) {
        $maxValue = 1;
    }
    $valueSuffix = isset($options['value_suffix']) ? (string)$options['value_suffix'] : '';
    if ($valueSuffix === '%' && $maxValue < 100) {
        $maxValue = 100;
    }
    $stepCount = (int)($options['grid_steps'] ?? 4);
    if ($stepCount < 2) {
        $stepCount = 4;
    }
    $axisMax = ceil($maxValue / 10) * 10;
    if ($valueSuffix === '%' && $axisMax < 100) {
        $axisMax = 100;
    }

    for ($i = 0; $i <= $stepCount; $i++) {
        $ratio = $i / $stepCount;
        $y = (int)round($marginTop + $chartHeight - ($ratio * $chartHeight));
        imageline($image, $marginLeft, $y, $marginLeft + $chartWidth, $y, $gridColor);
        $value = $axisMax * $ratio;
        $precision = (int)($options['decimal_places'] ?? 0);
        $label = number_format($value, $precision) . $valueSuffix;
        analytics_report_draw_text($image, $label, $textColor, 18, $marginLeft - 20, $y, [
            'align' => 'right',
            'baseline' => 'middle',
        ]);
    }

    imageline($image, $marginLeft, $marginTop + $chartHeight, $marginLeft + $chartWidth, $marginTop + $chartHeight, $axisColor);
    imageline($image, $marginLeft, $marginTop, $marginLeft, $marginTop + $chartHeight, $axisColor);

    $count = count($normalized);
    $segment = $chartWidth / max($count, 1);
    $barWidth = max(24, min(80, $segment * 0.6));
    $baseline = $marginTop + $chartHeight;
    $precision = (int)($options['decimal_places'] ?? 0);

    foreach ($normalized as $index => $point) {
        $value = max(0.0, min($axisMax, $point['value']));
        $heightRatio = $axisMax > 0 ? ($value / $axisMax) : 0;
        $barHeight = $heightRatio * $chartHeight;
        $centerX = $marginLeft + ($segment * $index) + ($segment / 2);
        $x1 = (int)round($centerX - ($barWidth / 2));
        $x2 = (int)round($centerX + ($barWidth / 2));
        $y1 = (int)round($baseline - $barHeight);
        $y2 = (int)round($baseline);

        imagefilledrectangle($image, $x1, $y1, $x2, $y2, $barColor);
        imageline($image, $x1, $y2, $x2, $y2, $barShadow);
        imageline($image, $x1, $y1, $x2, $y1, $barHighlight);

        $valueLabel = number_format($point['value'], $precision) . $valueSuffix;
        analytics_report_draw_text($image, $valueLabel, $textColor, 18, (int)round($centerX), $y1 - 12, [
            'align' => 'center',
            'baseline' => 'top',
        ]);

        $labelY = $baseline + 16;
        analytics_report_draw_text($image, $point['label'], $textColor, 18, (int)round($centerX), $labelY, [
            'align' => 'center',
            'baseline' => 'top',
        ]);
    }

    $result = analytics_report_export_gd_image($image);
    if ($result === null) {
        return null;
    }

    return $result;
}

function analytics_report_generate_line_chart(array $points, array $palette, array $options = []): ?array
{
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }

    $normalized = [];
    foreach ($points as $point) {
        $value = $point['value'] ?? ($point['score'] ?? null);
        if (!is_numeric($value)) {
            continue;
        }
        $normalized[] = [
            'label' => analytics_report_truncate_label((string)($point['label'] ?? '')),
            'value' => (float)$value,
            'count' => isset($point['count']) ? (int)$point['count'] : (int)($point['responses'] ?? 0),
        ];
    }

    if ($normalized === []) {
        return null;
    }

    $width = (int)($options['width'] ?? 1200);
    $height = (int)($options['height'] ?? 640);
    $image = imagecreatetruecolor($width, $height);
    if (!$image) {
        return null;
    }

    if (function_exists('imageantialias')) {
        @imageantialias($image, true);
    }

    $background = imagecolorallocate($image, 255, 255, 255);
    imagefilledrectangle($image, 0, 0, $width, $height, $background);

    $axisColor = imagecolorallocate($image, 148, 163, 184);
    $gridColor = imagecolorallocate($image, 226, 232, 240);
    $textColor = imagecolorallocate($image, 30, 41, 59);
    $plotBackground = imagecolorallocate($image, 248, 250, 252);

    $primaryRgb = analytics_report_color_to_rgb($palette['primary'] ?? '#2563eb');
    $lineRgb = $primaryRgb;
    $fillRgb = analytics_report_adjust_color($primaryRgb, 0.55);
    $pointRgb = analytics_report_adjust_color($primaryRgb, -0.1);
    $lineColor = imagecolorallocate($image, $lineRgb[0], $lineRgb[1], $lineRgb[2]);
    $fillColor = imagecolorallocate($image, $fillRgb[0], $fillRgb[1], $fillRgb[2]);
    $pointColor = imagecolorallocate($image, $pointRgb[0], $pointRgb[1], $pointRgb[2]);

    $marginLeft = 140;
    $marginRight = 80;
    $marginTop = 90;
    $marginBottom = 160;

    $chartWidth = $width - $marginLeft - $marginRight;
    $chartHeight = $height - $marginTop - $marginBottom;
    if ($chartWidth <= 0 || $chartHeight <= 0) {
        imagedestroy($image);
        return null;
    }

    imagefilledrectangle($image, $marginLeft, $marginTop, $marginLeft + $chartWidth, $marginTop + $chartHeight, $plotBackground);

    $values = array_column($normalized, 'value');
    $maxValue = max($values);
    if (isset($options['max_value']) && is_numeric($options['max_value'])) {
        $maxValue = max($maxValue, (float)$options['max_value']);
    }
    if ($maxValue <= 0) {
        $maxValue = 1;
    }
    $valueSuffix = isset($options['value_suffix']) ? (string)$options['value_suffix'] : '';
    if ($valueSuffix === '%' && $maxValue < 100) {
        $maxValue = 100;
    }
    $stepCount = (int)($options['grid_steps'] ?? 4);
    if ($stepCount < 2) {
        $stepCount = 4;
    }
    $axisMax = ceil($maxValue / 10) * 10;
    if ($valueSuffix === '%' && $axisMax < 100) {
        $axisMax = 100;
    }

    for ($i = 0; $i <= $stepCount; $i++) {
        $ratio = $i / $stepCount;
        $y = (int)round($marginTop + $chartHeight - ($ratio * $chartHeight));
        imageline($image, $marginLeft, $y, $marginLeft + $chartWidth, $y, $gridColor);
        $value = $axisMax * $ratio;
        $label = number_format($value, (int)($options['decimal_places'] ?? 0)) . $valueSuffix;
        analytics_report_draw_text($image, $label, $textColor, 18, $marginLeft - 20, $y, [
            'align' => 'right',
            'baseline' => 'middle',
        ]);
    }

    imageline($image, $marginLeft, $marginTop + $chartHeight, $marginLeft + $chartWidth, $marginTop + $chartHeight, $axisColor);
    imageline($image, $marginLeft, $marginTop, $marginLeft, $marginTop + $chartHeight, $axisColor);

    $count = count($normalized);
    $baseline = $marginTop + $chartHeight;
    $segment = $count > 1 ? ($chartWidth / ($count - 1)) : 0;
    $points = [];
    $polyline = [];

    foreach ($normalized as $index => $point) {
        $value = max(0.0, min($axisMax, $point['value']));
        $ratio = $axisMax > 0 ? ($value / $axisMax) : 0;
        $x = $count > 1
            ? $marginLeft + ($segment * $index)
            : $marginLeft + ($chartWidth / 2);
        $y = $baseline - ($ratio * $chartHeight);
        $points[] = [$x, $y, $point];
        $polyline[] = [$x, $y];
    }

    if (count($polyline) >= 2) {
        $polygon = [
            $marginLeft,
            $baseline,
        ];
        foreach ($polyline as [$x, $y]) {
            $polygon[] = (int)round($x);
            $polygon[] = (int)round($y);
        }
        $last = end($polyline);
        $polygon[] = (int)round($last[0]);
        $polygon[] = (int)round($baseline);
        imagefilledpolygon($image, $polygon, (int)(count($polygon) / 2), $fillColor);
    }

    if (function_exists('imagesetthickness')) {
        imagesetthickness($image, 3);
    }
    for ($i = 0; $i < count($polyline) - 1; $i++) {
        $start = $polyline[$i];
        $end = $polyline[$i + 1];
        imageline(
            $image,
            (int)round($start[0]),
            (int)round($start[1]),
            (int)round($end[0]),
            (int)round($end[1]),
            $lineColor
        );
    }
    if (function_exists('imagesetthickness')) {
        imagesetthickness($image, 1);
    }

    foreach ($points as [$x, $y, $meta]) {
        $radius = 8;
        imagefilledellipse($image, (int)round($x), (int)round($y), $radius, $radius, $pointColor);
        imageellipse($image, (int)round($x), (int)round($y), $radius + 2, $radius + 2, $lineColor);

        $valueLabel = number_format($meta['value'], (int)($options['decimal_places'] ?? 0)) . $valueSuffix;
        analytics_report_draw_text($image, $valueLabel, $textColor, 18, (int)round($x), (int)round($y) - 14, [
            'align' => 'center',
            'baseline' => 'bottom',
        ]);

        analytics_report_draw_text($image, $meta['label'], $textColor, 18, (int)round($x), $baseline + 16, [
            'align' => 'center',
            'baseline' => 'top',
        ]);
    }

    $result = analytics_report_export_gd_image($image);
    if ($result === null) {
        return null;
    }

    return $result;
}

function analytics_report_adjust_color(array $rgb, float $factor): array
{
    $factor = max(-1.0, min(1.0, $factor));
    $result = [];
    foreach ($rgb as $channel) {
        $channel = (int)$channel;
        if ($factor >= 0) {
            $result[] = (int)round($channel + (255 - $channel) * $factor);
        } else {
            $result[] = (int)round($channel * (1.0 + $factor));
        }
    }
    return $result;
}

function analytics_report_draw_text($image, string $text, int $color, float $fontSize, int $x, int $y, array $options = []): void
{
    $align = $options['align'] ?? 'left';
    $baseline = $options['baseline'] ?? 'alphabetic';
    $fontPath = analytics_report_default_font_path();

    if ($fontPath && function_exists('imagettftext') && function_exists('imagettfbbox')) {
        $box = imagettfbbox($fontSize, 0, $fontPath, $text);
        $textWidth = analytics_report_ttf_box_width($box);
        $textHeight = analytics_report_ttf_box_height($box);
        $drawX = $x;
        if ($align === 'center') {
            $drawX = (int)round($x - $textWidth / 2);
        } elseif ($align === 'right') {
            $drawX = (int)round($x - $textWidth);
        }
        $drawY = $y;
        if ($baseline === 'top') {
            $drawY = (int)round($y + $textHeight);
        } elseif ($baseline === 'middle') {
            $drawY = (int)round($y + $textHeight / 2);
        }
        imagettftext($image, $fontSize, 0, $drawX, $drawY, $color, $fontPath, $text);
        return;
    }

    $font = $options['font'] ?? 3;
    $charWidth = imagefontwidth($font);
    $charHeight = imagefontheight($font);
    $textWidth = $charWidth * strlen($text);
    $drawX = $x;
    if ($align === 'center') {
        $drawX = (int)round($x - $textWidth / 2);
    } elseif ($align === 'right') {
        $drawX = (int)round($x - $textWidth);
    }
    $drawY = $y;
    if ($baseline === 'top') {
        $drawY = $y;
    } elseif ($baseline === 'middle') {
        $drawY = (int)round($y - ($charHeight / 2));
    } else { // baseline or bottom
        $drawY = (int)round($y - $charHeight);
    }
    imagestring($image, $font, $drawX, $drawY, $text, $color);
}

function analytics_report_truncate_label(string $label, int $limit = 22): string
{
    $trimmed = trim($label);
    if ($trimmed === '') {
        return '';
    }

    $length = function_exists('mb_strlen') ? mb_strlen($trimmed, 'UTF-8') : strlen($trimmed);
    if ($length <= $limit) {
        return $trimmed;
    }

    $slice = function_exists('mb_substr')
        ? mb_substr($trimmed, 0, $limit - 1, 'UTF-8')
        : substr($trimmed, 0, $limit - 1);

    return rtrim($slice) . '…';
}

function analytics_report_header_logo_spec(SimplePdfDocument $pdf, array $cfg): ?array
{
    $logoData = analytics_report_prepare_logo_payload($cfg);
    if ($logoData === null) {
        return null;
    }

    $imageName = $pdf->registerJpegImage($logoData['data'], $logoData['width'], $logoData['height']);
    $dimensions = analytics_report_logo_display_dimensions($logoData['width'], $logoData['height']);

    return [
        'name' => $imageName,
        'width' => $dimensions['width'],
        'height' => $dimensions['height'],
    ];
}

function analytics_report_prepare_logo_payload(array $cfg): ?array
{
    $paths = [];
    if (function_exists('branding_logo_full_path')) {
        $candidate = branding_logo_full_path($cfg['logo_path'] ?? null);
        if (is_string($candidate) && $candidate !== '') {
            $paths[] = $candidate;
        }
    } elseif (!empty($cfg['logo_path']) && function_exists('base_path')) {
        $paths[] = base_path((string)$cfg['logo_path']);
    }

    foreach ($paths as $path) {
        $payload = analytics_report_convert_image_file_to_jpeg($path);
        if ($payload !== null) {
            return $payload;
        }
    }

    return analytics_report_generate_placeholder_logo($cfg);
}

function analytics_report_convert_image_file_to_jpeg(string $path): ?array
{
    if ($path === '' || !is_file($path)) {
        return null;
    }

    $contents = @file_get_contents($path);
    if ($contents === false) {
        return null;
    }

    return analytics_report_convert_image_blob_to_jpeg($contents);
}

function analytics_report_convert_image_blob_to_jpeg(string $blob): ?array
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        return null;
    }

    $source = @imagecreatefromstring($blob);
    if (!$source) {
        return null;
    }

    $width = imagesx($source);
    $height = imagesy($source);
    if ($width <= 0 || $height <= 0) {
        imagedestroy($source);
        return null;
    }

    $canvas = imagecreatetruecolor($width, $height);
    if (!$canvas) {
        imagedestroy($source);
        return null;
    }

    $background = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $width, $height, $background);
    imagealphablending($canvas, true);
    imagealphablending($source, true);
    if (function_exists('imagesavealpha')) {
        imagesavealpha($source, true);
    }
    imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
    imagedestroy($source);

    return analytics_report_export_gd_image($canvas);
}

function analytics_report_export_gd_image($image): ?array
{
    if (!function_exists('imagejpeg')) {
        return null;
    }

    $validImage = is_resource($image);
    if (!$validImage && class_exists('GdImage', false)) {
        $validImage = $image instanceof GdImage;
    }

    if (!$validImage) {
        return null;
    }

    $width = imagesx($image);
    $height = imagesy($image);
    if ($width <= 0 || $height <= 0) {
        imagedestroy($image);
        return null;
    }

    ob_start();
    $success = imagejpeg($image, null, 90);
    $binary = ob_get_clean();
    imagedestroy($image);

    if (!$success || !is_string($binary) || $binary === '') {
        return null;
    }

    return [
        'data' => $binary,
        'width' => $width,
        'height' => $height,
    ];
}

function analytics_report_generate_placeholder_logo(array $cfg): ?array
{
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        return null;
    }

    $width = 420;
    $height = 160;
    $image = imagecreatetruecolor($width, $height);
    if (!$image) {
        return null;
    }
    imagealphablending($image, true);

    $palette = analytics_report_palette_colors($cfg);
    $primaryRgb = analytics_report_color_to_rgb($palette['primary']);
    $accentRgb = analytics_report_color_to_rgb($palette['light']);
    $borderRgb = analytics_report_color_to_rgb($palette['dark']);
    $textHex = analytics_report_contrast_for_color($palette['primary']);
    $textRgb = analytics_report_color_to_rgb($textHex);

    $primaryColor = imagecolorallocate($image, $primaryRgb[0], $primaryRgb[1], $primaryRgb[2]);
    $accentColor = imagecolorallocate($image, $accentRgb[0], $accentRgb[1], $accentRgb[2]);
    $borderColor = imagecolorallocate($image, $borderRgb[0], $borderRgb[1], $borderRgb[2]);

    imagefilledrectangle($image, 0, 0, $width, $height, $primaryColor);
    imagefilledrectangle($image, 12, 12, $width - 12, $height - 12, $accentColor);
    imagerectangle($image, 0, 0, $width - 1, $height - 1, $borderColor);

    $initials = analytics_report_site_initials((string)($cfg['site_name'] ?? ''));
    $fontPath = analytics_report_default_font_path();
    if ($fontPath !== null && is_file($fontPath) && function_exists('imagettftext')) {
        $fontSize = 72;
        $maxWidth = $width - 80;
        while ($fontSize > 32) {
            $box = imagettfbbox($fontSize, 0, $fontPath, $initials);
            if ($box !== false && analytics_report_ttf_box_width($box) <= $maxWidth) {
                break;
            }
            $fontSize -= 4;
        }

        $box = imagettfbbox($fontSize, 0, $fontPath, $initials);
        if ($box !== false) {
            $textWidth = analytics_report_ttf_box_width($box);
            $textHeight = analytics_report_ttf_box_height($box);
            $textColor = imagecolorallocate($image, $textRgb[0], $textRgb[1], $textRgb[2]);
            $offsetX = min($box[0], $box[2], $box[4], $box[6]);
            $offsetY = min($box[5], $box[7], $box[1], $box[3]);
            $x = (int)round(($width - $textWidth) / 2 - $offsetX);
            $y = (int)round(($height + $textHeight) / 2 - $offsetY);
            imagettftext($image, $fontSize, 0, $x, $y, $textColor, $fontPath, $initials);
        }
    } else {
        $textColor = imagecolorallocate($image, $textRgb[0], $textRgb[1], $textRgb[2]);
        $font = 5;
        $textWidth = imagefontwidth($font) * strlen($initials);
        $textHeight = imagefontheight($font);
        $x = (int)round(($width - $textWidth) / 2);
        $y = (int)round(($height - $textHeight) / 2);
        imagestring($image, $font, $x, $y, $initials, $textColor);
    }

    return analytics_report_export_gd_image($image);
}

function analytics_report_logo_display_dimensions(int $pixelWidth, int $pixelHeight): array
{
    $maxWidth = 220.0;
    $maxHeight = 120.0;
    $minWidth = 90.0;
    $scale = 0.75;
    $displayWidth = max(1, $pixelWidth) * $scale;
    $displayHeight = max(1, $pixelHeight) * $scale;

    if ($displayWidth > $maxWidth) {
        $scale = $maxWidth / $displayWidth;
        $displayWidth = $maxWidth;
        $displayHeight *= $scale;
    }

    if ($displayHeight > $maxHeight) {
        $scale = $maxHeight / $displayHeight;
        $displayHeight = $maxHeight;
        $displayWidth *= $scale;
    }

    if ($displayWidth < $minWidth) {
        $scale = $minWidth / max(0.1, $displayWidth);
        $displayWidth = $minWidth;
        $displayHeight *= $scale;
        if ($displayHeight > $maxHeight) {
            $scale = $maxHeight / $displayHeight;
            $displayHeight = $maxHeight;
            $displayWidth *= $scale;
        }
    }

    return [
        'width' => round($displayWidth, 2),
        'height' => round($displayHeight, 2),
    ];
}

function analytics_report_header_tagline(array $cfg): ?string
{
    $candidates = [
        $cfg['landing_text'] ?? '',
        $cfg['footer_org_name'] ?? '',
        $cfg['footer_org_short'] ?? '',
        $cfg['contact'] ?? '',
        $cfg['address'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $text = analytics_report_trim_text($candidate);
        if ($text !== null) {
            return $text;
        }
    }

    return null;
}

function analytics_report_trim_text($value, int $limit = 140): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $normalized = preg_replace('/\s+/u', ' ', $value);
    $trimmed = trim($normalized ?? '');
    if ($trimmed === '') {
        return null;
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($trimmed) > $limit) {
            $trimmed = rtrim(mb_substr($trimmed, 0, $limit - 1)) . '…';
        }
    } elseif (strlen($trimmed) > $limit) {
        $trimmed = rtrim(substr($trimmed, 0, $limit - 1)) . '…';
    }

    return $trimmed;
}

function analytics_report_site_initials(string $name): string
{
    $normalized = trim($name);
    if ($normalized === '') {
        return 'HR';
    }

    $parts = preg_split('/[^A-Za-z0-9]+/u', $normalized);
    $initials = '';
    if (is_array($parts)) {
        foreach ($parts as $part) {
            $piece = trim($part);
            if ($piece === '') {
                continue;
            }
            $initials .= function_exists('mb_strtoupper') ? mb_strtoupper(mb_substr($piece, 0, 1)) : strtoupper(substr($piece, 0, 1));
            if ((function_exists('mb_strlen') ? mb_strlen($initials) : strlen($initials)) >= 3) {
                break;
            }
        }
    }

    if ($initials === '') {
        $initials = function_exists('mb_strtoupper') ? mb_strtoupper(mb_substr($normalized, 0, 2)) : strtoupper(substr($normalized, 0, 2));
    }

    return function_exists('mb_substr') ? mb_substr($initials, 0, 3) : substr($initials, 0, 3);
}

function analytics_report_palette_colors(array $cfg): array
{
    $defaults = [
        'primary' => '#2073bf',
        'light' => '#4d94d8',
        'dark' => '#155a94',
    ];

    if (function_exists('site_brand_palette')) {
        try {
            $palette = site_brand_palette($cfg);
            if (is_array($palette)) {
                $defaults['primary'] = $palette['primary'] ?? $defaults['primary'];
                $defaults['light'] = $palette['primaryLight'] ?? ($palette['secondary'] ?? $defaults['light']);
                $defaults['dark'] = $palette['primaryDark'] ?? $defaults['dark'];
            }
        } catch (Throwable $e) {
            // ignore palette errors and use defaults
        }
    }

    return $defaults;
}

function analytics_report_color_to_rgb(string $hex): array
{
    $value = trim($hex);
    if ($value === '') {
        return [0, 0, 0];
    }

    if ($value[0] === '#') {
        $value = substr($value, 1);
    }

    if (strlen($value) === 3) {
        $value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
    }

    if (strlen($value) !== 6 || preg_match('/[^0-9a-fA-F]/', $value)) {
        return [0, 0, 0];
    }

    return [
        hexdec(substr($value, 0, 2)),
        hexdec(substr($value, 2, 2)),
        hexdec(substr($value, 4, 2)),
    ];
}

function analytics_report_contrast_for_color(string $hex): string
{
    if (function_exists('contrast_color')) {
        try {
            return contrast_color($hex);
        } catch (Throwable $e) {
            // ignore and use manual fallback
        }
    }

    $rgb = analytics_report_color_to_rgb($hex);
    $luminance = 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
    return $luminance > 140 ? '#000000' : '#FFFFFF';
}

function analytics_report_default_font_path(): ?string
{
    $candidates = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function analytics_report_ttf_box_width(array $box): float
{
    $xs = [$box[0], $box[2], $box[4], $box[6]];
    return max($xs) - min($xs);
}

function analytics_report_ttf_box_height(array $box): float
{
    $ys = [$box[1], $box[3], $box[5], $box[7]];
    return max($ys) - min($ys);
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
