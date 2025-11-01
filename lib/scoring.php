<?php
/**
 * Helper functions for questionnaire scoring calculations.
 */

/**
 * Build a stable key for identifying questionnaire items when assigning weights.
 */
function questionnaire_item_weight_key(array $item): string
{
    $linkId = '';
    foreach (['linkId', 'linkid'] as $key) {
        if (array_key_exists($key, $item)) {
            $candidate = trim((string)$item[$key]);
            if ($candidate !== '') {
                $linkId = $candidate;
                break;
            }
        }
    }
    if ($linkId !== '') {
        return $linkId;
    }
    if (isset($item['id'])) {
        $id = (int)$item['id'];
        if ($id > 0) {
            return '__id:' . $id;
        }
    }
    if (isset($item['questionnaire_item_id'])) {
        $id = (int)$item['questionnaire_item_id'];
        if ($id > 0) {
            return '__qid:' . $id;
        }
    }
    return '__hash:' . sha1(json_encode($item));
}

/**
 * Determine even weights for all Likert items in a questionnaire.
 *
 * @param array<int, array<string, mixed>> $items
 * @param float $totalWeight Weight budget to distribute. Defaults to 100.
 *
 * @return array<string, float> Mapping of item key to assigned weight.
 */
function questionnaire_even_likert_weights(array $items, float $totalWeight = 100.0): array
{
    $keys = [];
    $reservedWeight = 0.0;
    foreach ($items as $item) {
        $type = strtolower((string)($item['type'] ?? ''));
        if ($type !== 'likert') {
            continue;
        }

        $hasExplicitWeight = false;
        $explicitWeight = 0.0;
        foreach (['weight_percent', 'weight'] as $field) {
            if (!array_key_exists($field, $item)) {
                continue;
            }
            $raw = $item[$field];
            if ($raw === null || $raw === '') {
                continue;
            }
            $candidate = (float)$raw;
            if ($candidate > 0.0) {
                $hasExplicitWeight = true;
                $explicitWeight = $candidate;
                break;
            }
        }
        if ($hasExplicitWeight) {
            $reservedWeight += $explicitWeight;
            continue;
        }

        $key = questionnaire_item_weight_key($item);
        if ($key === '') {
            continue;
        }
        $keys[$key] = true;
    }
    if ($keys === []) {
        return [];
    }
    $count = count($keys);
    if ($count <= 0) {
        return [];
    }
    $availableWeight = $totalWeight - $reservedWeight;
    if ($availableWeight < 0.0) {
        $availableWeight = 0.0;
    }
    $evenWeight = $availableWeight / $count;
    $weights = [];
    foreach (array_keys($keys) as $key) {
        $weights[$key] = $evenWeight;
    }
    return $weights;
}

/**
 * Resolve the effective weight for a questionnaire item.
 *
 * @param array<string, mixed> $item Item metadata including optional weight fields.
 * @param array<string, float> $likertWeights Pre-computed even weights for Likert items.
 * @param bool $isScorable Whether the item contributes to scoring.
 */
function questionnaire_resolve_effective_weight(array $item, array $likertWeights, bool $isScorable): float
{
    if (!$isScorable) {
        return 0.0;
    }
    foreach (['weight_percent', 'weight'] as $field) {
        if (!array_key_exists($field, $item)) {
            continue;
        }
        $raw = $item[$field];
        if ($raw === null || $raw === '') {
            continue;
        }
        $candidate = (float)$raw;
        if ($candidate > 0.0) {
            return $candidate;
        }
    }
    $type = strtolower((string)($item['type'] ?? ''));
    if ($type === 'likert') {
        $key = questionnaire_item_weight_key($item);
        if ($key !== '' && isset($likertWeights[$key])) {
            return (float)$likertWeights[$key];
        }
    }
    return 1.0;
}
