<?php
require_once __DIR__.'/utils.php';
$entries = array();
$qs = $pdo->query('SELECT * FROM questionnaire ORDER BY id DESC');
foreach ($qs as $q) {
    $itemsStmt = $pdo->prepare('SELECT id, linkId, text, type, allow_multiple, COALESCE(weight_percent,0) AS weight_percent FROM questionnaire_item WHERE questionnaire_id=? ORDER BY order_index ASC');
    $itemsStmt->execute(array($q['id']));
    $items = $itemsStmt->fetchAll();
    $optionsMap = array();
    if ($items) {
        $itemIds = array_column($items, 'id');
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $optStmt = $pdo->prepare("SELECT questionnaire_item_id, value FROM questionnaire_item_option WHERE questionnaire_item_id IN ($placeholders) ORDER BY questionnaire_item_id, order_index, id");
        $optStmt->execute($itemIds);
        foreach ($optStmt->fetchAll() as $row) {
            $itemId = (int)$row['questionnaire_item_id'];
            if (!isset($optionsMap[$itemId])) {
                $optionsMap[$itemId] = array();
            }
            $optionsMap[$itemId][] = $row['value'];
        }
    }
    $questionnaireItems = array_map(static function ($it) use ($optionsMap) {
        $type = $it['type'];
        if ($type === 'textarea') {
            $type = 'text';
        }
        $entry = array(
            'linkId' => $it['linkId'],
            'text' => $it['text'],
            'type' => $type,
            'extension' => array(array(
                'url' => 'http://example.org/fhir/StructureDefinition/weightPercent',
                'valueInteger' => (int)$it['weight_percent'],
            )),
        );
        if ($it['type'] === 'choice') {
            if (!empty($it['allow_multiple'])) {
                $entry['repeats'] = true;
            }
            $itemId = (int)$it['id'];
            $opts = isset($optionsMap[$itemId]) ? $optionsMap[$itemId] : array();
            if ($opts) {
                $entry['answerOption'] = array_map(static function ($value) {
                    return array('valueString' => $value);
                }, $opts);
            }
        }
        return $entry;
    }, $items);
    $entries[] = array(
        'resource' => array(
            'resourceType' => 'Questionnaire',
            'id' => $q['id'],
            'title' => $q['title'],
            'description' => $q['description'],
            'item' => $questionnaireItems,
        ),
    );
}
echo json_encode(bundle($entries));
