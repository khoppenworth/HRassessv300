<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/scoring.php';

$items = [
    ['id' => 1, 'linkId' => 'likert_a', 'type' => 'likert'],
    ['id' => 2, 'linkId' => 'likert_b', 'type' => 'likert', 'weight_percent' => 25],
    ['id' => 3, 'linkId' => 'bool_a', 'type' => 'boolean', 'weight_percent' => 15],
    ['id' => 4, 'linkId' => 'text_a', 'type' => 'text'],
];

$likertWeights = questionnaire_even_likert_weights($items);
if (count($likertWeights) !== 2) {
    fwrite(STDERR, "Expected two likert weights.\n");
    exit(1);
}

$weightA = questionnaire_resolve_effective_weight($items[0], $likertWeights, true);
$weightB = questionnaire_resolve_effective_weight($items[1], $likertWeights, true);
$weightBoolean = questionnaire_resolve_effective_weight($items[2], $likertWeights, true);
$weightText = questionnaire_resolve_effective_weight($items[3], $likertWeights, true);

if (abs($weightA - 50.0) > 0.001) {
    fwrite(STDERR, "Likert auto weight calculation failed.\n");
    exit(1);
}

if (abs($weightB - 25.0) > 0.001) {
    fwrite(STDERR, "Explicit likert weight should override auto distribution.\n");
    exit(1);
}

if (abs($weightBoolean - 15.0) > 0.001) {
    fwrite(STDERR, "Boolean weight was lost when likert auto weights were present.\n");
    exit(1);
}

if ($weightText !== 0.0) {
    fwrite(STDERR, "Unweighted non-likert item should not receive implicit weight.\n");
    exit(1);
}

$nonScorable = ['id' => 5, 'linkId' => 'section_1', 'type' => 'section', 'weight_percent' => 10];
if (questionnaire_resolve_effective_weight($nonScorable, $likertWeights, false) !== 0.0) {
    fwrite(STDERR, "Non-scorable items must yield zero weight.\n");
    exit(1);
}

echo "Questionnaire scoring tests passed.\n";
