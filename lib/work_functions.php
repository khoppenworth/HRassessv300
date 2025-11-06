<?php
declare(strict_types=1);

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

if (!function_exists('canonical_work_function_key')) {
    function canonical_work_function_key(string $value, ?array $defaults = null): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if ($defaults === null) {
            $defaults = default_work_function_definitions();
        }

        if (isset($defaults[$value])) {
            return $value;
        }

        foreach ($defaults as $key => $label) {
            if (strcasecmp($value, $key) === 0 || strcasecmp($value, (string)$label) === 0) {
                return $key;
            }
        }

        $normalized = strtolower($value);
        $normalized = preg_replace('/[^a-z0-9]+/i', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_');

        if ($normalized !== '' && isset($defaults[$normalized])) {
            return $normalized;
        }

        return '';
    }
}

if (!function_exists('canonical')) {
    /**
     * @deprecated Use canonical_work_function_key() instead.
     */
    function canonical(string $value, ?array $defaults = null): string
    {
        return canonical_work_function_key($value, $defaults);
    }
}

if (!function_exists('work_function_choices')) {
    function work_function_choices(PDO $pdo, bool $forceRefresh = false): array
    {
        static $cache = null;
        if ($forceRefresh) {
            $cache = null;
        }
        if ($cache !== null) {
            return $cache;
        }

        $defaults = default_work_function_definitions();
        $values = [];

        try {
            $stmt = $pdo->query('SELECT DISTINCT work_function FROM questionnaire_work_function ORDER BY work_function');
            if ($stmt) {
                $values = array_filter(array_map(static fn($value) => trim((string)$value), $stmt->fetchAll(PDO::FETCH_COLUMN)));
            }
        } catch (PDOException $e) {
            error_log('work_function_choices (questionnaire_work_function): ' . $e->getMessage());
        }

        if (!$values) {
            try {
                $stmt = $pdo->query('SELECT DISTINCT work_function FROM users WHERE work_function IS NOT NULL AND work_function <> \'\' ORDER BY work_function');
                if ($stmt) {
                    $values = array_filter(array_map(static fn($value) => trim((string)$value), $stmt->fetchAll(PDO::FETCH_COLUMN)));
                }
            } catch (PDOException $e) {
                error_log('work_function_choices (users): ' . $e->getMessage());
            }
        }

        if (!$values) {
            $values = array_keys($defaults);
        }

        $choices = [];
        foreach ($values as $value) {
            $key = canonical_work_function_key((string)$value, $defaults);
            if ($key === '') {
                continue;
            }
            $choices[$key] = $defaults[$key] ?? ucwords(str_replace('_', ' ', (string)$key));
        }

        if ($choices === []) {
            foreach ($defaults as $key => $label) {
                $choices[$key] = $label;
            }
        } else {
            foreach ($defaults as $key => $label) {
                if (!isset($choices[$key])) {
                    $choices[$key] = $label;
                }
            }
        }

        uasort($choices, static function ($a, $b) {
            return strcasecmp((string)$a, (string)$b);
        });

        $cache = $choices;

        return $cache;
    }
}

if (!function_exists('is_valid_work_function')) {
    function is_valid_work_function(PDO $pdo, string $value): bool
    {
        return $value === '' ? false : array_key_exists($value, work_function_choices($pdo));
    }
}

if (!function_exists('work_function_label')) {
    function work_function_label(PDO $pdo, string $value): string
    {
        $choices = work_function_choices($pdo);
        $canonical = canonical_work_function_key($value);
        if ($canonical !== '' && isset($choices[$canonical])) {
            return $choices[$canonical];
        }

        return $choices[$value] ?? $value;
    }
}
