<?php
declare(strict_types=1);


final class WorkFunctionService
{
    /** @var WorkFunctionService|null */
    private static $instance = null;

    /**
     * @var array<string,string>
     */
    private array $definitions;

    /**
     * @var array<int,array<string,string>>
     */
    private array $choicesCache = [];

    /**
     * @var array<int,array<string,array<int>>>
     */
    private array $assignmentCache = [];

    private function __construct(array $definitions)
    {
        $this->definitions = $definitions;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self(self::defaultDefinitions());
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * @return array<string,string>
     */
    public static function defaultDefinitions(): array
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
     * @return array<string,string>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * @param array<string,string>|null $definitions
     */
    public function canonicalize(string $value, ?array $definitions = null): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $definitions = $definitions ?? $this->definitions;
        if (isset($definitions[$value])) {
            return $value;
        }

        foreach ($definitions as $key => $label) {
            if (strcasecmp($value, (string) $key) === 0 || strcasecmp($value, (string) $label) === 0) {
                return (string) $key;
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

    /**
     * @return array<string,string>
     */
    public function choices(PDO $pdo, bool $forceRefresh = false): array
    {
        $cacheKey = spl_object_id($pdo);
        if ($forceRefresh) {
            unset($this->choicesCache[$cacheKey]);
        }

        if (isset($this->choicesCache[$cacheKey])) {
            return $this->choicesCache[$cacheKey];
        }

        $values = [];

        try {
            $stmt = $pdo->query('SELECT DISTINCT work_function FROM questionnaire_work_function ORDER BY work_function');
            if ($stmt) {
                $values = array_filter(array_map(static fn ($value) => trim((string) $value), $stmt->fetchAll(PDO::FETCH_COLUMN)));
            }
        } catch (PDOException $e) {
            error_log('work_function_choices (questionnaire_work_function): ' . $e->getMessage());
        }

        if (!$values) {
            try {
                $stmt = $pdo->query("SELECT DISTINCT work_function FROM users WHERE work_function IS NOT NULL AND work_function <> '' ORDER BY work_function");
                if ($stmt) {
                    $values = array_filter(array_map(static fn ($value) => trim((string) $value), $stmt->fetchAll(PDO::FETCH_COLUMN)));
                }
            } catch (PDOException $e) {
                error_log('work_function_choices (users): ' . $e->getMessage());
            }
        }

        if (!$values) {
            $values = array_keys($this->definitions);
        }

        $choices = [];
        foreach ($values as $value) {
            $key = $this->canonicalize((string) $value);
            if ($key === '') {
                continue;
            }
            $choices[$key] = $this->definitions[$key] ?? ucwords(str_replace('_', ' ', (string) $key));
        }

        if ($choices === []) {
            foreach ($this->definitions as $key => $label) {
                $choices[$key] = $label;
            }
        } else {
            foreach ($this->definitions as $key => $label) {
                if (!isset($choices[$key])) {
                    $choices[$key] = $label;
                }
            }
        }

        uasort($choices, static fn ($a, $b) => strcasecmp((string) $a, (string) $b));

        $this->choicesCache[$cacheKey] = $choices;

        return $choices;
    }

    public function isValid(PDO $pdo, string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $canonical = $this->canonicalize($value);
        if ($canonical === '') {
            return false;
        }

        $choices = $this->choices($pdo);

        return isset($choices[$canonical]);
    }

    public function label(PDO $pdo, string $value): string
    {
        $choices = $this->choices($pdo);
        $canonical = $this->canonicalize($value);
        if ($canonical !== '' && isset($choices[$canonical])) {
            return $choices[$canonical];
        }

        return $choices[$value] ?? ($this->definitions[$value] ?? $value);
    }

    /**
     * @return array<string,list<int>>
     */
    public function assignments(PDO $pdo, bool $forceRefresh = false): array
    {
        $cacheKey = spl_object_id($pdo);
        if ($forceRefresh) {
            unset($this->assignmentCache[$cacheKey]);
        }

        if (isset($this->assignmentCache[$cacheKey])) {
            return $this->assignmentCache[$cacheKey];
        }

        $assignments = [];
        try {
            $stmt = $pdo->query('SELECT questionnaire_id, work_function FROM questionnaire_work_function');
            if ($stmt) {
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $questionnaireId = isset($row['questionnaire_id']) ? (int) $row['questionnaire_id'] : 0;
                    $workFunction = $this->canonicalize((string) ($row['work_function'] ?? ''));
                    if ($questionnaireId <= 0 || $workFunction === '') {
                        continue;
                    }
                    $assignments[$workFunction][$questionnaireId] = $questionnaireId;
                }
            }
        } catch (PDOException $e) {
            error_log('work_function_assignments: ' . $e->getMessage());
        }

        foreach ($assignments as $workFunction => $ids) {
            ksort($ids);
            $assignments[$workFunction] = array_values($ids);
        }

        ksort($assignments);
        $this->assignmentCache[$cacheKey] = $assignments;

        return $assignments;
    }

    /**
     * @param array<string,list<int>> $assignments
     */
    public function saveAssignments(PDO $pdo, array $assignments): void
    {
        $this->choicesCache = [];
        $this->assignmentCache = [];

        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM questionnaire_work_function');
            if ($assignments !== []) {
                $stmt = $pdo->prepare('INSERT INTO questionnaire_work_function (questionnaire_id, work_function) VALUES (?, ?)');
                foreach ($assignments as $workFunction => $ids) {
                    foreach ($ids as $questionnaireId) {
                        $stmt->execute([(int) $questionnaireId, (string) $workFunction]);
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string>        $allowedWorkFunctions
     * @param list<int>           $allowedQuestionnaireIds
     *
     * @return array<string,list<int>>
     */
    public function normalizeAssignments(array $input, array $allowedWorkFunctions, array $allowedQuestionnaireIds): array
    {
        $validWorkFunctions = [];
        foreach ($allowedWorkFunctions as $workFunction) {
            $canonical = $this->canonicalize((string) $workFunction);
            if ($canonical !== '') {
                $validWorkFunctions[$canonical] = $canonical;
            }
        }

        $validQuestionnaires = [];
        foreach ($allowedQuestionnaireIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $validQuestionnaires[$id] = $id;
            }
        }

        $normalized = [];
        foreach ($input as $workFunction => $values) {
            $canonical = $this->canonicalize((string) $workFunction);
            if ($canonical === '' || !isset($validWorkFunctions[$canonical])) {
                continue;
            }

            if (!is_array($values)) {
                $values = [];
            }

            $collected = [];
            foreach ($values as $value) {
                $id = (int) $value;
                if ($id > 0 && isset($validQuestionnaires[$id])) {
                    $collected[$id] = $id;
                }
            }

            $normalized[$canonical] = array_values($collected);
        }

        foreach ($validWorkFunctions as $canonical) {
            if (!isset($normalized[$canonical])) {
                $normalized[$canonical] = [];
            }
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @return array<string,string>
     */
    public function availableWorkFunctions(PDO $pdo): array
    {
        $keys = array_merge(
            array_keys($this->definitions),
            array_keys($this->choices($pdo)),
            array_keys($this->assignments($pdo))
        );

        $keys = array_map(fn ($value) => $this->canonicalize((string) $value), $keys);
        $keys = array_filter(array_unique($keys), static fn ($value) => $value !== '');

        $labels = [];
        foreach ($keys as $key) {
            $labels[$key] = $this->label($pdo, $key);
        }

        uasort($labels, static fn ($a, $b) => strcasecmp((string) $a, (string) $b));

        return $labels;
    }
}

function work_function_service(): WorkFunctionService
{
    return WorkFunctionService::instance();
}

/**
 * @return array<string,string>
 */
function default_work_function_definitions(): array
{
    return work_function_service()->definitions();
}

function canonical_work_function_key(string $value, ?array $defaults = null): string
{
    return work_function_service()->canonicalize($value, $defaults);
}

function canonical(string $value, ?array $defaults = null): string
{
    return canonical_work_function_key($value, $defaults);
}

/**
 * @return array<string,string>
 */
function work_function_choices(PDO $pdo, bool $forceRefresh = false): array
{
    return work_function_service()->choices($pdo, $forceRefresh);
}

function is_valid_work_function(PDO $pdo, string $value): bool
{
    return work_function_service()->isValid($pdo, $value);
}

function work_function_label(PDO $pdo, string $value): string
{
    return work_function_service()->label($pdo, $value);
}

/**
 * @return array<string,list<int>>
 */
function work_function_assignments(PDO $pdo, bool $forceRefresh = false): array
{
    return work_function_service()->assignments($pdo, $forceRefresh);
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
    return work_function_service()->normalizeAssignments($input, $allowedWorkFunctions, $allowedQuestionnaireIds);
}

/**
 * @return array<string,string>
 */
function available_work_functions(PDO $pdo): array
{
    return work_function_service()->availableWorkFunctions($pdo);
}
