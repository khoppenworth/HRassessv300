<?php
declare(strict_types=1);

/**
 * Retrieve the built-in work function definition list.
 *
 * @return array<string,string>
 */
function default_work_function_definitions(): array
{
    return [
        'finance' => 'Finance',
        'general_service' => 'General Service',
        'hrm' => 'HRM',
        'ict' => 'ICT',
        'leadership_tn' => 'Leadership TN',
        'legal_service' => 'Legal Service',
        'pme' => 'PME',
        'quantification' => 'Quantification',
        'records_documentation' => 'Records & Documentation',
        'security_driver' => 'Security & Driver',
        'security' => 'Security',
        'tmd' => 'TMD',
        'wim' => 'WIM',
        'cmd' => 'CMD',
        'communication' => 'Communication',
        'dfm' => 'DFM',
        'driver' => 'Driver',
        'ethics' => 'Ethics',
    ];
}

/**
 * Normalize a work function identifier to the canonical key.
 *
 * @param array<string,string>|null $definitions
 */
function canonical_work_function_key(string $value, ?array $definitions = null): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $definitions = $definitions ?? default_work_function_definitions();
    if (isset($definitions[$value])) {
        return $value;
    }

    foreach ($definitions as $key => $label) {
        if (strcasecmp($value, (string)$key) === 0 || strcasecmp($value, (string)$label) === 0) {
            return (string)$key;
        }
    }

    $normalized = strtolower($value);
    $normalized = preg_replace('/[^a-z0-9]+/i', '_', $normalized) ?? '';
    $normalized = trim($normalized, '_');

    if ($normalized !== '' && isset($definitions[$normalized])) {
        return $normalized;
    }

    return '';
}

function canonical(string $value, ?array $definitions = null): string
{
    return canonical_work_function_key($value, $definitions);
}

/**
 * @return array<string,string>
 */
function work_function_choices(PDO $pdo, bool $forceRefresh = false): array
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo);

    if (!$forceRefresh && isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $definitions = default_work_function_definitions();
    $choices = $definitions;
    $sources = [];

    try {
        $stmt = $pdo->query('SELECT DISTINCT work_function FROM questionnaire_work_function WHERE work_function <> "" ORDER BY work_function');
        if ($stmt) {
            $sources = array_merge($sources, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
    } catch (PDOException $e) {
        error_log('work_function_choices (questionnaire_work_function): ' . $e->getMessage());
    }

    try {
        $stmt = $pdo->query("SELECT DISTINCT work_function FROM users WHERE work_function IS NOT NULL AND work_function <> '' ORDER BY work_function");
        if ($stmt) {
            $sources = array_merge($sources, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
    } catch (PDOException $e) {
        error_log('work_function_choices (users): ' . $e->getMessage());
    }

    foreach ($sources as $rawValue) {
        $key = canonical_work_function_key((string)$rawValue, $definitions);
        if ($key === '') {
            continue;
        }
        if (!isset($choices[$key])) {
            $choices[$key] = $definitions[$key] ?? ucwords(str_replace('_', ' ', (string)$key));
        }
    }

    uasort($choices, static fn ($a, $b) => strcasecmp((string)$a, (string)$b));

    $cache[$cacheKey] = $choices;

    return $choices;
}

function is_valid_work_function(PDO $pdo, string $value): bool
{
    if ($value === '') {
        return false;
    }

    $canonical = canonical_work_function_key($value);
    if ($canonical === '') {
        return false;
    }

    $choices = work_function_choices($pdo);

    return isset($choices[$canonical]);
}

function work_function_label(PDO $pdo, string $value): string
{
    $choices = work_function_choices($pdo);
    $canonical = canonical_work_function_key($value);

    if ($canonical !== '' && isset($choices[$canonical])) {
        return $choices[$canonical];
    }

    if ($canonical !== '') {
        return ucwords(str_replace('_', ' ', $canonical));
    }

    $value = trim($value);

    return $value !== '' ? ucwords(str_replace('_', ' ', $value)) : '';
}

/**
 * @return array<string,list<int>>
 */
function work_function_assignments(PDO $pdo, bool $forceRefresh = false): array
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo);

    if (!$forceRefresh && isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $assignments = [];

    try {
        $stmt = $pdo->query('SELECT questionnaire_id, work_function FROM questionnaire_work_function');
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $questionnaireId = isset($row['questionnaire_id']) ? (int)$row['questionnaire_id'] : 0;
                $workFunction = canonical_work_function_key((string)($row['work_function'] ?? ''));
                if ($questionnaireId <= 0 || $workFunction === '') {
                    continue;
                }
                if (!isset($assignments[$workFunction])) {
                    $assignments[$workFunction] = [];
                }
                $assignments[$workFunction][] = $questionnaireId;
            }
        }
    } catch (PDOException $e) {
        error_log('work_function_assignments: ' . $e->getMessage());
    }

    foreach ($assignments as $workFunction => $ids) {
        $filtered = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $filtered[$id] = $id;
            }
        }
        $list = array_values($filtered);
        sort($list, SORT_NUMERIC);
        $assignments[$workFunction] = $list;
    }

    ksort($assignments);

    $cache[$cacheKey] = $assignments;

    return $assignments;
}

/**
 * @param array<string,mixed> $input
 * @param list<string>        $allowedWorkFunctions
 * @param list<int>           $allowedQuestionnaireIds
 *
 * @return array<string,list<int>>
 */
function normalize_work_function_assignments(array $input, array $allowedWorkFunctions, array $allowedQuestionnaireIds): array
{
    $allowedWorkFunctions = array_values(array_unique(array_map('strval', $allowedWorkFunctions)));
    $allowedWorkFunctionSet = array_fill_keys($allowedWorkFunctions, true);

    $allowedQuestionnaireIds = array_values(array_unique(array_map('intval', $allowedQuestionnaireIds)));
    $allowedQuestionnaireSet = array_fill_keys($allowedQuestionnaireIds, true);

    $normalized = [];

    foreach ($input as $workFunction => $ids) {
        $canonical = canonical_work_function_key((string)$workFunction);
        if ($canonical === '' || !isset($allowedWorkFunctionSet[$canonical])) {
            continue;
        }

        if (!is_array($ids)) {
            continue;
        }

        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0 || !isset($allowedQuestionnaireSet[$id])) {
                continue;
            }
            if (!isset($normalized[$canonical])) {
                $normalized[$canonical] = [];
            }
            $normalized[$canonical][$id] = $id;
        }
    }

    foreach ($allowedWorkFunctions as $workFunction) {
        if (!isset($normalized[$workFunction])) {
            $normalized[$workFunction] = [];
            continue;
        }
        $values = array_values($normalized[$workFunction]);
        sort($values, SORT_NUMERIC);
        $normalized[$workFunction] = $values;
    }

    ksort($normalized);

    return $normalized;
}

/**
 * Persist the normalized assignments to the database.
 *
 * @param array<string,list<int>> $assignments
 */
function save_work_function_assignments(PDO $pdo, array $assignments): void
{
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM questionnaire_work_function');
        if ($assignments !== []) {
            $insert = $pdo->prepare('INSERT INTO questionnaire_work_function (questionnaire_id, work_function) VALUES (?, ?)');
            foreach ($assignments as $workFunction => $questionnaireIds) {
                $workFunction = canonical_work_function_key((string)$workFunction);
                if ($workFunction === '') {
                    continue;
                }
                foreach ($questionnaireIds as $questionnaireId) {
                    $questionnaireId = (int)$questionnaireId;
                    if ($questionnaireId <= 0) {
                        continue;
                    }
                    $insert->execute([$questionnaireId, $workFunction]);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    work_function_assignments($pdo, true);
    work_function_choices($pdo, true);
    available_work_functions($pdo, true);
}

/**
 * @return array<string,string>
 */
function available_work_functions(PDO $pdo, bool $forceRefresh = false): array
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo);

    if (!$forceRefresh && isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $definitions = default_work_function_definitions();
    $choices = work_function_choices($pdo, $forceRefresh);
    $assignments = work_function_assignments($pdo, $forceRefresh);

    $keys = array_unique(array_merge(array_keys($definitions), array_keys($choices), array_keys($assignments)));

    $labels = [];
    foreach ($keys as $key) {
        $canonical = canonical_work_function_key((string)$key, $definitions);
        if ($canonical === '') {
            continue;
        }
        if (isset($choices[$canonical])) {
            $labels[$canonical] = $choices[$canonical];
            continue;
        }
        if (isset($definitions[$canonical])) {
            $labels[$canonical] = $definitions[$canonical];
            continue;
        }
        $labels[$canonical] = ucwords(str_replace('_', ' ', $canonical));
    }

    uasort($labels, static fn ($a, $b) => strcasecmp((string)$a, (string)$b));

    $cache[$cacheKey] = $labels;

    return $labels;
use PDO;
use PDOException;

if (!function_exists('default_work_function_definitions')) {
    function default_work_function_definitions(): array
    {
        return [
            'finance' => 'Finance',
            'general_service' => 'General Service',
            'hrm' => 'HRM',
            'ict' => 'ICT',
            'leadership_tn' => 'Leadership TN',
            'legal_service' => 'Legal Service',
            'pme' => 'PME',
            'quantification' => 'Quantification',
            'records_documentation' => 'Records & Documentation',
            'security_driver' => 'Security & Driver',
            'security' => 'Security',
            'tmd' => 'TMD',
            'wim' => 'WIM',
            'cmd' => 'CMD',
            'communication' => 'Communication',
            'dfm' => 'DFM',
            'driver' => 'Driver',
            'ethics' => 'Ethics',
        ];
    }
}

/**
 * Normalize a work function identifier to the canonical key.
 *
 * @param array<string,string>|null $definitions
 */
function canonical_work_function_key(string $value, ?array $definitions = null): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $definitions = $definitions ?? default_work_function_definitions();
    if (isset($definitions[$value])) {
        return $value;
    }

    foreach ($definitions as $key => $label) {
        if (strcasecmp($value, (string)$key) === 0 || strcasecmp($value, (string)$label) === 0) {
            return (string)$key;
        }
    }

    $normalized = strtolower($value);
    $normalized = preg_replace('/[^a-z0-9]+/i', '_', $normalized) ?? '';
    $normalized = trim($normalized, '_');

    if ($normalized !== '' && isset($definitions[$normalized])) {
        return $normalized;
    }

    return '';
}

function canonical(string $value, ?array $definitions = null): string
{
    return canonical_work_function_key($value, $definitions);
}

/**
 * @return array<string,string>
 */
function work_function_choices(PDO $pdo, bool $forceRefresh = false): array
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo);

    if (!$forceRefresh && isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $definitions = default_work_function_definitions();
    $choices = $definitions;
    $sources = [];

    try {
        $stmt = $pdo->query('SELECT DISTINCT work_function FROM questionnaire_work_function WHERE work_function <> "" ORDER BY work_function');
        if ($stmt) {
            $sources = array_merge($sources, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
    } catch (PDOException $e) {
        error_log('work_function_choices (questionnaire_work_function): ' . $e->getMessage());
    }

    try {
        $stmt = $pdo->query("SELECT DISTINCT work_function FROM users WHERE work_function IS NOT NULL AND work_function <> '' ORDER BY work_function");
        if ($stmt) {
            $sources = array_merge($sources, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
    } catch (PDOException $e) {
        error_log('work_function_choices (users): ' . $e->getMessage());
    }

    foreach ($sources as $rawValue) {
        $key = canonical_work_function_key((string)$rawValue, $definitions);
        if ($key === '') {
            continue;
        }
        if (!isset($choices[$key])) {
            $choices[$key] = $definitions[$key] ?? ucwords(str_replace('_', ' ', (string)$key));
        }
    }

    uasort($choices, static fn ($a, $b) => strcasecmp((string)$a, (string)$b));

    $cache[$cacheKey] = $choices;

    return $choices;
}

function is_valid_work_function(PDO $pdo, string $value): bool
{
    if ($value === '') {
        return false;
    }

    $canonical = canonical_work_function_key($value);
    if ($canonical === '') {
        return false;
    }

    $choices = work_function_choices($pdo);

    return isset($choices[$canonical]);
}

function work_function_label(PDO $pdo, string $value): string
{
    $choices = work_function_choices($pdo);
    $canonical = canonical_work_function_key($value);

    if ($canonical !== '' && isset($choices[$canonical])) {
        return $choices[$canonical];
    }

    if ($canonical !== '') {
        return ucwords(str_replace('_', ' ', $canonical));
    }

    $value = trim($value);

    return $value !== '' ? ucwords(str_replace('_', ' ', $value)) : '';
}

/**
 * @return array<string,list<int>>
 */
function work_function_assignments(PDO $pdo, bool $forceRefresh = false): array
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo);

    if (!$forceRefresh && isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $assignments = [];

    try {
        $stmt = $pdo->query('SELECT questionnaire_id, work_function FROM questionnaire_work_function');
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $questionnaireId = isset($row['questionnaire_id']) ? (int)$row['questionnaire_id'] : 0;
                $workFunction = canonical_work_function_key((string)($row['work_function'] ?? ''));
                if ($questionnaireId <= 0 || $workFunction === '') {
                    continue;
                }
                if (!isset($assignments[$workFunction])) {
                    $assignments[$workFunction] = [];
                }
                $assignments[$workFunction][] = $questionnaireId;
            }
        }
    } catch (PDOException $e) {
        error_log('work_function_assignments: ' . $e->getMessage());
    }

    foreach ($assignments as $workFunction => $ids) {
        $filtered = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $filtered[$id] = $id;
            }
        }
        $list = array_values($filtered);
        sort($list, SORT_NUMERIC);
        $assignments[$workFunction] = $list;
    }

    ksort($assignments);

    $cache[$cacheKey] = $assignments;

    return $assignments;
}

/**
 * @param array<string,mixed> $input
 * @param list<string>        $allowedWorkFunctions
 * @param list<int>           $allowedQuestionnaireIds
 *
 * @return array<string,list<int>>
 */
function normalize_work_function_assignments(array $input, array $allowedWorkFunctions, array $allowedQuestionnaireIds): array
{
    $allowedWorkFunctions = array_values(array_unique(array_map('strval', $allowedWorkFunctions)));
    $allowedWorkFunctionSet = array_fill_keys($allowedWorkFunctions, true);

    $allowedQuestionnaireIds = array_values(array_unique(array_map('intval', $allowedQuestionnaireIds)));
    $allowedQuestionnaireSet = array_fill_keys($allowedQuestionnaireIds, true);

    $normalized = [];

    foreach ($input as $workFunction => $ids) {
        $canonical = canonical_work_function_key((string)$workFunction);
        if ($canonical === '' || !isset($allowedWorkFunctionSet[$canonical])) {
            continue;
        }

        if (!is_array($ids)) {
            continue;
        }

        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0 || !isset($allowedQuestionnaireSet[$id])) {
                continue;
            }
            if (!isset($normalized[$canonical])) {
                $normalized[$canonical] = [];
            }
            $normalized[$canonical][$id] = $id;
        }
    }

    foreach ($allowedWorkFunctions as $workFunction) {
        if (!isset($normalized[$workFunction])) {
            $normalized[$workFunction] = [];
            continue;
        }
        $values = array_values($normalized[$workFunction]);
        sort($values, SORT_NUMERIC);
        $normalized[$workFunction] = $values;
    }

    ksort($normalized);

    return $normalized;
}

/**
 * Persist the normalized assignments to the database.
 *
 * @param array<string,list<int>> $assignments
 */
function save_work_function_assignments(PDO $pdo, array $assignments): void
{
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM questionnaire_work_function');
        if ($assignments !== []) {
            $insert = $pdo->prepare('INSERT INTO questionnaire_work_function (questionnaire_id, work_function) VALUES (?, ?)');
            foreach ($assignments as $workFunction => $questionnaireIds) {
                $workFunction = canonical_work_function_key((string)$workFunction);
                if ($workFunction === '') {
                    continue;
                }
                foreach ($questionnaireIds as $questionnaireId) {
                    $questionnaireId = (int)$questionnaireId;
                    if ($questionnaireId <= 0) {
                        continue;
                    }
                    $insert->execute([$questionnaireId, $workFunction]);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    work_function_assignments($pdo, true);
    work_function_choices($pdo, true);
    available_work_functions($pdo, true);
}

/**
 * @return array<string,string>
 */
function available_work_functions(PDO $pdo, bool $forceRefresh = false): array
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo);

    if (!$forceRefresh && isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $definitions = default_work_function_definitions();
    $choices = work_function_choices($pdo, $forceRefresh);
    $assignments = work_function_assignments($pdo, $forceRefresh);

    $keys = array_unique(array_merge(array_keys($definitions), array_keys($choices), array_keys($assignments)));

    $labels = [];
    foreach ($keys as $key) {
        $canonical = canonical_work_function_key((string)$key, $definitions);
        if ($canonical === '') {
            continue;
        }
        if (isset($choices[$canonical])) {
            $labels[$canonical] = $choices[$canonical];
            continue;
        }
        if (isset($definitions[$canonical])) {
            $labels[$canonical] = $definitions[$canonical];
            continue;
        }
        $labels[$canonical] = ucwords(str_replace('_', ' ', $canonical));
    }

    uasort($labels, static fn ($a, $b) => strcasecmp((string)$a, (string)$b));

    $cache[$cacheKey] = $labels;

    return $labels;
}
