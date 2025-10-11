<?php
require_once __DIR__.'/../config.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

const LIKERT_DEFAULT_OPTIONS = [
    '1 - Strongly Disagree',
    '2 - Disagree',
    '3 - Neutral',
    '4 - Agree',
    '5 - Strongly Agree',
];

function send_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function resolve_csrf_token(?array $payload = null): string {
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        return (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    if (isset($_GET['csrf'])) {
        return (string)$_GET['csrf'];
    }
    if (isset($_POST['csrf'])) {
        return (string)$_POST['csrf'];
    }
    if ($payload && isset($payload['csrf'])) {
        return (string)$payload['csrf'];
    }
    return '';
}

function ensure_csrf(?array $payload = null): void {
    $token = resolve_csrf_token($payload);
    if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        send_json([
            'status' => 'error',
            'message' => 'Invalid CSRF token',
        ], 400);
    }
}

$action = $_GET['action'] ?? '';

if ($action === 'fetch') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        send_json(['status' => 'error', 'message' => 'Method not allowed'], 405);
    }
    ensure_csrf();

    $qsRows = $pdo->query('SELECT * FROM questionnaire ORDER BY id DESC')->fetchAll();
    $sectionsRows = $pdo->query('SELECT * FROM questionnaire_section ORDER BY questionnaire_id, order_index, id')->fetchAll();
    $itemsRows = $pdo->query('SELECT * FROM questionnaire_item ORDER BY questionnaire_id, order_index, id')->fetchAll();
    $optionsRows = $pdo->query('SELECT * FROM questionnaire_item_option ORDER BY questionnaire_item_id, order_index, id')->fetchAll();
    $wfRows = $pdo->query('SELECT questionnaire_id, work_function FROM questionnaire_work_function')->fetchAll();

    $sectionsByQuestionnaire = [];
    foreach ($sectionsRows as $section) {
        $qid = (int)$section['questionnaire_id'];
        $sectionsByQuestionnaire[$qid][] = [
            'id' => (int)$section['id'],
            'questionnaire_id' => $qid,
            'title' => $section['title'],
            'description' => $section['description'],
            'order_index' => (int)$section['order_index'],
        ];
    }

    $optionsByItem = [];
    foreach ($optionsRows as $option) {
        $itemId = (int)$option['questionnaire_item_id'];
        $optionsByItem[$itemId][] = [
            'id' => (int)$option['id'],
            'questionnaire_item_id' => $itemId,
            'value' => $option['value'],
            'order_index' => (int)$option['order_index'],
        ];
    }

    $itemsByQuestionnaire = [];
    $itemsBySection = [];
    foreach ($itemsRows as $item) {
        $qid = (int)$item['questionnaire_id'];
        $sid = $item['section_id'] !== null ? (int)$item['section_id'] : null;
        $formatted = [
            'id' => (int)$item['id'],
            'questionnaire_id' => $qid,
            'section_id' => $sid,
            'linkId' => $item['linkId'],
            'text' => $item['text'],
            'type' => $item['type'],
            'order_index' => (int)$item['order_index'],
            'weight_percent' => (int)$item['weight_percent'],
            'allow_multiple' => (bool)$item['allow_multiple'],
            'options' => $optionsByItem[(int)$item['id']] ?? [],
        ];
        if ($sid) {
            $itemsBySection[$sid][] = $formatted;
        } else {
            $itemsByQuestionnaire[$qid][] = $formatted;
        }
    }

    $workFunctionsByQuestionnaire = [];
    foreach ($wfRows as $wf) {
        $qid = (int)$wf['questionnaire_id'];
        $workFunctionsByQuestionnaire[$qid][] = $wf['work_function'];
    }

    $questionnaires = [];
    foreach ($qsRows as $row) {
        $qid = (int)$row['id'];
        $sections = [];
        foreach ($sectionsByQuestionnaire[$qid] ?? [] as $section) {
            $sectionId = $section['id'];
            $sections[] = $section + [
                'items' => $itemsBySection[$sectionId] ?? [],
            ];
        }
        $questionnaires[] = [
            'id' => $qid,
            'title' => $row['title'],
            'description' => $row['description'],
            'created_at' => $row['created_at'],
            'sections' => $sections,
            'items' => $itemsByQuestionnaire[$qid] ?? [],
            'work_functions' => $workFunctionsByQuestionnaire[$qid] ?? WORK_FUNCTIONS,
        ];
    }

    send_json([
        'status' => 'ok',
        'csrf' => csrf_token(),
        'questionnaires' => $questionnaires,
    ]);
}

if ($action === 'save' || $action === 'publish') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_json(['status' => 'error', 'message' => 'Method not allowed'], 405);
    }
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        send_json(['status' => 'error', 'message' => 'Invalid payload'], 400);
    }
    ensure_csrf($payload);

    $structures = $payload['questionnaires'] ?? [];
    if (!is_array($structures)) {
        send_json(['status' => 'error', 'message' => 'Invalid questionnaire data'], 400);
    }

    $existingQs = $pdo->query('SELECT * FROM questionnaire ORDER BY id')->fetchAll();
    $questionnaireMap = [];
    foreach ($existingQs as $row) {
        $questionnaireMap[(int)$row['id']] = $row;
    }

    $sectionsRows = $pdo->query('SELECT * FROM questionnaire_section ORDER BY questionnaire_id, id')->fetchAll();
    $sectionsMap = [];
    foreach ($sectionsRows as $row) {
        $qid = (int)$row['questionnaire_id'];
        $sectionsMap[$qid][(int)$row['id']] = $row;
    }

    $itemsRows = $pdo->query('SELECT * FROM questionnaire_item ORDER BY questionnaire_id, id')->fetchAll();
    $optionsRows = $pdo->query('SELECT * FROM questionnaire_item_option ORDER BY questionnaire_item_id, id')->fetchAll();
    $itemsMap = [];
    foreach ($itemsRows as $row) {
        $qid = (int)$row['questionnaire_id'];
        $itemsMap[$qid][(int)$row['id']] = $row;
    }

    $optionsMap = [];
    foreach ($optionsRows as $row) {
        $itemId = (int)$row['questionnaire_item_id'];
        $optionsMap[$itemId][(int)$row['id']] = $row;
    }

    $questionnaireSeen = [];
    $idMap = [
        'questionnaires' => [],
        'sections' => [],
        'items' => [],
        'options' => [],
    ];

    $pdo->beginTransaction();
    try {
        $insertQuestionnaireStmt = $pdo->prepare('INSERT INTO questionnaire (title, description) VALUES (?, ?)');
        $updateQuestionnaireStmt = $pdo->prepare('UPDATE questionnaire SET title=?, description=? WHERE id=?');

        $insertSectionStmt = $pdo->prepare('INSERT INTO questionnaire_section (questionnaire_id, title, description, order_index) VALUES (?, ?, ?, ?)');
        $updateSectionStmt = $pdo->prepare('UPDATE questionnaire_section SET title=?, description=?, order_index=? WHERE id=?');

        $insertItemStmt = $pdo->prepare('INSERT INTO questionnaire_item (questionnaire_id, section_id, linkId, text, type, order_index, weight_percent, allow_multiple) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $updateItemStmt = $pdo->prepare('UPDATE questionnaire_item SET section_id=?, linkId=?, text=?, type=?, order_index=?, weight_percent=?, allow_multiple=? WHERE id=?');
        $insertOptionStmt = $pdo->prepare('INSERT INTO questionnaire_item_option (questionnaire_item_id, value, order_index) VALUES (?, ?, ?)');
        $updateOptionStmt = $pdo->prepare('UPDATE questionnaire_item_option SET value=?, order_index=? WHERE id=?');
        $insertWorkFunctionStmt = $pdo->prepare('INSERT INTO questionnaire_work_function (questionnaire_id, work_function) VALUES (?, ?)');
        $deleteWorkFunctionStmt = $pdo->prepare('DELETE FROM questionnaire_work_function WHERE questionnaire_id=?');

        $saveOptions = function (int $itemId, $optionsInput) use (&$optionsMap, $insertOptionStmt, $updateOptionStmt, &$idMap, $pdo) {
            $existing = $optionsMap[$itemId] ?? [];
            if (!is_array($optionsInput)) {
                $optionsInput = [];
            }
            $seen = [];
            $order = 1;
            foreach ($optionsInput as $optionData) {
                if (!is_array($optionData)) {
                    continue;
                }
                $value = trim((string)($optionData['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $optionClientId = $optionData['clientId'] ?? null;
                $optionId = isset($optionData['id']) ? (int)$optionData['id'] : null;
                if ($optionId && isset($existing[$optionId])) {
                    $updateOptionStmt->execute([$value, $order, $optionId]);
                } else {
                    $insertOptionStmt->execute([$itemId, $value, $order]);
                    $optionId = (int)$pdo->lastInsertId();
                    if ($optionClientId) {
                        $idMap['options'][$optionClientId] = $optionId;
                    }
                }
                $seen[] = $optionId;
                $order++;
            }
            $toDelete = array_diff(array_keys($existing), $seen);
            if ($toDelete) {
                $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
                $stmt = $pdo->prepare("DELETE FROM questionnaire_item_option WHERE id IN ($placeholders)");
                $stmt->execute(array_values($toDelete));
            }
            $optionsMap[$itemId] = [];
            foreach ($seen as $optionId) {
                $optionsMap[$itemId][$optionId] = ['id' => $optionId];
            }
        };

        foreach ($structures as $qData) {
            if (!is_array($qData)) {
                continue;
            }
            $clientId = $qData['clientId'] ?? null;
            $qid = isset($qData['id']) ? (int)$qData['id'] : null;
            $title = trim((string)($qData['title'] ?? ''));
            $description = $qData['description'] ?? null;

            if ($qid && isset($questionnaireMap[$qid])) {
                $updateQuestionnaireStmt->execute([$title, $description, $qid]);
            } else {
                $insertQuestionnaireStmt->execute([$title, $description]);
                $qid = (int)$pdo->lastInsertId();
                if ($clientId) {
                    $idMap['questionnaires'][$clientId] = $qid;
                }
                $questionnaireMap[$qid] = [
                    'id' => $qid,
                    'title' => $title,
                    'description' => $description,
                ];
            }
            $questionnaireSeen[] = $qid;

            $sectionSeen = [];
            $itemSeen = [];

            $existingSections = $sectionsMap[$qid] ?? [];
            $existingItems = $itemsMap[$qid] ?? [];

            $sectionsInput = $qData['sections'] ?? [];
            if (!is_array($sectionsInput)) {
                $sectionsInput = [];
            }

            $orderIndex = 1;
            foreach ($sectionsInput as $sectionData) {
                if (!is_array($sectionData)) {
                    continue;
                }
                $sectionClientId = $sectionData['clientId'] ?? null;
                $sectionId = isset($sectionData['id']) ? (int)$sectionData['id'] : null;
                $sectionTitle = trim((string)($sectionData['title'] ?? ''));
                $sectionDescription = $sectionData['description'] ?? null;

                if ($sectionId && isset($existingSections[$sectionId])) {
                    $updateSectionStmt->execute([$sectionTitle, $sectionDescription, $orderIndex, $sectionId]);
                } else {
                    $insertSectionStmt->execute([$qid, $sectionTitle, $sectionDescription, $orderIndex]);
                    $sectionId = (int)$pdo->lastInsertId();
                    if ($sectionClientId) {
                        $idMap['sections'][$sectionClientId] = $sectionId;
                    }
                    $existingSections[$sectionId] = [
                        'id' => $sectionId,
                    ];
                }
                $sectionSeen[] = $sectionId;

                $itemsInput = $sectionData['items'] ?? [];
                if (!is_array($itemsInput)) {
                    $itemsInput = [];
                }
                $itemOrder = 1;
                foreach ($itemsInput as $itemData) {
                    if (!is_array($itemData)) {
                        continue;
                    }
                    $itemClientId = $itemData['clientId'] ?? null;
                    $itemId = isset($itemData['id']) ? (int)$itemData['id'] : null;
                    $linkId = trim((string)($itemData['linkId'] ?? ''));
                    $text = trim((string)($itemData['text'] ?? ''));
                    $type = $itemData['type'] ?? 'text';
                    if (!in_array($type, ['likert', 'text', 'textarea', 'boolean', 'choice'], true)) {
                        $type = 'likert';
                    }
                    $weight = isset($itemData['weight_percent']) ? (int)$itemData['weight_percent'] : 0;
                    $allowMultiple = !empty($itemData['allow_multiple']);
                    if ($type !== 'choice') {
                        $allowMultiple = false;
                    }

                    if ($itemId && isset($existingItems[$itemId])) {
                        $updateItemStmt->execute([$sectionId, $linkId, $text, $type, $itemOrder, $weight, $allowMultiple ? 1 : 0, $itemId]);
                    } else {
                        $insertItemStmt->execute([$qid, $sectionId, $linkId, $text, $type, $itemOrder, $weight, $allowMultiple ? 1 : 0]);
                        $itemId = (int)$pdo->lastInsertId();
                        if ($itemClientId) {
                            $idMap['items'][$itemClientId] = $itemId;
                        }
                        $existingItems[$itemId] = [
                            'id' => $itemId,
                        ];
                    }
                    $optionsInput = $itemData['options'] ?? [];
                    if (!in_array($type, ['choice', 'likert'], true)) {
                        $optionsInput = [];
                    }
                    $saveOptions($itemId, $optionsInput);
                    $itemSeen[] = $itemId;
                    $itemOrder++;
                }
                $orderIndex++;
            }

            $rootItemsInput = $qData['items'] ?? [];
            if (!is_array($rootItemsInput)) {
                $rootItemsInput = [];
            }
            $rootOrder = 1;
            foreach ($rootItemsInput as $itemData) {
                if (!is_array($itemData)) {
                    continue;
                }
                $itemClientId = $itemData['clientId'] ?? null;
                $itemId = isset($itemData['id']) ? (int)$itemData['id'] : null;
                $linkId = trim((string)($itemData['linkId'] ?? ''));
                $text = trim((string)($itemData['text'] ?? ''));
                $type = $itemData['type'] ?? 'text';
                if (!in_array($type, ['likert', 'text', 'textarea', 'boolean', 'choice'], true)) {
                    $type = 'likert';
                }
                $weight = isset($itemData['weight_percent']) ? (int)$itemData['weight_percent'] : 0;
                $allowMultiple = !empty($itemData['allow_multiple']);
                if ($type !== 'choice') {
                    $allowMultiple = false;
                }

                if ($itemId && isset($existingItems[$itemId])) {
                    $updateItemStmt->execute([null, $linkId, $text, $type, $rootOrder, $weight, $allowMultiple ? 1 : 0, $itemId]);
                } else {
                    $insertItemStmt->execute([$qid, null, $linkId, $text, $type, $rootOrder, $weight, $allowMultiple ? 1 : 0]);
                    $itemId = (int)$pdo->lastInsertId();
                    if ($itemClientId) {
                        $idMap['items'][$itemClientId] = $itemId;
                    }
                    $existingItems[$itemId] = [
                        'id' => $itemId,
                    ];
                }
                $optionsInput = $itemData['options'] ?? [];
                if (!in_array($type, ['choice', 'likert'], true)) {
                    $optionsInput = [];
                }
                $saveOptions($itemId, $optionsInput);
                $itemSeen[] = $itemId;
                $rootOrder++;
            }

            $itemsToDelete = array_diff(array_keys($existingItems), $itemSeen);
            if ($itemsToDelete) {
                $placeholders = implode(',', array_fill(0, count($itemsToDelete), '?'));
                $stmt = $pdo->prepare("DELETE FROM questionnaire_item WHERE id IN ($placeholders)");
                $stmt->execute(array_values($itemsToDelete));
            }

            $workFunctionsInput = $qData['work_functions'] ?? WORK_FUNCTIONS;
            if (!is_array($workFunctionsInput)) {
                $workFunctionsInput = WORK_FUNCTIONS;
            }
            $allowedFunctions = [];
            foreach ($workFunctionsInput as $wf) {
                if (is_string($wf) && in_array($wf, WORK_FUNCTIONS, true)) {
                    $allowedFunctions[] = $wf;
                }
            }
            if (!$allowedFunctions) {
                $allowedFunctions = WORK_FUNCTIONS;
            }
            $deleteWorkFunctionStmt->execute([$qid]);
            foreach ($allowedFunctions as $wf) {
                $insertWorkFunctionStmt->execute([$qid, $wf]);
            }

            $sectionsToDelete = array_diff(array_keys($existingSections), $sectionSeen);
            if ($sectionsToDelete) {
                $placeholders = implode(',', array_fill(0, count($sectionsToDelete), '?'));
                $stmt = $pdo->prepare("DELETE FROM questionnaire_section WHERE id IN ($placeholders)");
                $stmt->execute(array_values($sectionsToDelete));
            }
        }

        $deleteQuestionnaires = array_diff(array_keys($questionnaireMap), $questionnaireSeen);
        if ($deleteQuestionnaires) {
            $placeholders = implode(',', array_fill(0, count($deleteQuestionnaires), '?'));
            $stmt = $pdo->prepare("DELETE FROM questionnaire WHERE id IN ($placeholders)");
            $stmt->execute(array_values($deleteQuestionnaires));
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        send_json([
            'status' => 'error',
            'message' => 'Failed to persist questionnaire data',
            'detail' => $e->getMessage(),
        ], 500);
    }

    send_json([
        'status' => 'ok',
        'message' => $action === 'publish' ? 'Questionnaires published' : 'Questionnaires saved',
        'idMap' => $idMap,
        'csrf' => csrf_token(),
    ]);
}

$msg = '';
if (isset($_POST['import'])) {
    csrf_check();
    if (!empty($_FILES['file']['tmp_name'])) {
        $raw = file_get_contents($_FILES['file']['tmp_name']);
        $data = null;
        if (stripos($_FILES['file']['name'], '.json') !== false) {
            $data = json_decode($raw, true);
        } else {
            $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($xml !== false) {
                $json = json_encode($xml);
                $data = json_decode($json, true);
            }
        }
        if ($data) {
            $qs = [];
            if (($data['resourceType'] ?? '') === 'Bundle') {
                foreach ($data['entry'] ?? [] as $entry) {
                    if (($entry['resource']['resourceType'] ?? '') === 'Questionnaire') {
                        $qs[] = $entry['resource'];
                    }
                }
            } elseif (($data['resourceType'] ?? '') === 'Questionnaire') {
                $qs[] = $data;
            }
            foreach ($qs as $resource) {
                $pdo->prepare('INSERT INTO questionnaire (title, description) VALUES (?, ?)')
                    ->execute([
                        $resource['title'] ?? 'FHIR Questionnaire',
                        $resource['description'] ?? null,
                    ]);
                $qid = (int)$pdo->lastInsertId();
                foreach (WORK_FUNCTIONS as $wf) {
                    $pdo->prepare('INSERT INTO questionnaire_work_function (questionnaire_id, work_function) VALUES (?, ?)')
                        ->execute([$qid, $wf]);
                }

                $insertSectionStmt = $pdo->prepare('INSERT INTO questionnaire_section (questionnaire_id, title, description, order_index) VALUES (?, ?, ?, ?)');
                $insertItemStmt = $pdo->prepare('INSERT INTO questionnaire_item (questionnaire_id, section_id, linkId, text, type, order_index, weight_percent, allow_multiple) VALUES (?,?,?,?,?,?,?,?)');
                $insertOptionStmt = $pdo->prepare('INSERT INTO questionnaire_item_option (questionnaire_item_id, value, order_index) VALUES (?,?,?)');

                $sectionOrder = 1;
                $itemOrder = 1;

                $toList = static function ($value) {
                    if (!is_array($value)) {
                        return [];
                    }
                    $expected = 0;
                    foreach (array_keys($value) as $key) {
                        if ((string)$key !== (string)$expected) {
                            return [$value];
                        }
                        $expected++;
                    }
                    return $value;
                };

                $mapType = static function ($type) {
                    $type = strtolower((string)$type);
                    switch ($type) {
                        case 'boolean':
                            return 'boolean';
                        case 'likert':
                        case 'scale':
                            return 'likert';
                        case 'choice':
                            return 'choice';
                        case 'text':
                        case 'textarea':
                            return 'textarea';
                        default:
                            return 'text';
                    }
                };

                $isTruthy = static function ($value): bool {
                    if (is_array($value)) {
                        if (isset($value['@attributes']['value'])) {
                            $value = $value['@attributes']['value'];
                        } elseif (isset($value['value'])) {
                            $value = $value['value'];
                        } else {
                            $value = reset($value);
                        }
                    }
                    if (is_string($value)) {
                        $value = trim($value);
                    }
                    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
                };

                $processItems = function ($items, $sectionId = null) use (&$processItems, &$sectionOrder, &$itemOrder, $insertSectionStmt, $insertItemStmt, $insertOptionStmt, $qid, $toList, $mapType, $pdo, $isTruthy) {
                    $items = $toList($items);
                    foreach ($items as $it) {
                        if (!is_array($it)) {
                            continue;
                        }
                        $children = $it['item'] ?? [];
                        $childList = $toList($children);
                        $type = strtolower($it['type'] ?? '');
                        $hasChildren = !empty($childList);

                        if ($hasChildren || $type === 'group') {
                            $title = $it['text'] ?? ($it['linkId'] ?? ('Section '.$sectionOrder));
                            $description = $it['description'] ?? null;
                            $insertSectionStmt->execute([$qid, $title, $description, $sectionOrder]);
                            $newSectionId = (int)$pdo->lastInsertId();
                            $sectionOrder++;
                            if ($hasChildren) {
                                $processItems($childList, $newSectionId);
                            }
                            continue;
                        }

                        if ($type === 'display') {
                            // Display items are headers or text blocks; skip them.
                            continue;
                        }

                        $linkId = $it['linkId'] ?? ('i'.$itemOrder);
                        $text = $it['text'] ?? $linkId;
                        $allowMultiple = isset($it['repeats']) ? $isTruthy($it['repeats']) : false;
                        $dbType = $mapType($type);
                        $itemOrderIndex = $itemOrder;
                        $insertItemStmt->execute([
                            $qid,
                            $sectionId,
                            $linkId,
                            $text,
                            $dbType,
                            $itemOrderIndex,
                            0,
                            $dbType === 'choice' && $allowMultiple ? 1 : 0,
                        ]);
                        $itemId = (int)$pdo->lastInsertId();
                        if ($dbType === 'choice' || $dbType === 'likert') {
                            $options = $toList($it['answerOption'] ?? []);
                            $optionOrder = 1;
                            foreach ($options as $option) {
                                if (!is_array($option)) {
                                    continue;
                                }
                                $value = null;
                                if (isset($option['valueString'])) {
                                    $value = $option['valueString'];
                                } elseif (isset($option['valueCoding']['display'])) {
                                    $value = $option['valueCoding']['display'];
                                } elseif (isset($option['valueCoding']['code'])) {
                                    $value = $option['valueCoding']['code'];
                                }
                                $value = trim((string)($value ?? ''));
                                if ($value === '') {
                                    continue;
                                }
                                $insertOptionStmt->execute([$itemId, $value, $optionOrder]);
                                $optionOrder++;
                            }
                            if ($dbType === 'likert' && $optionOrder === 1) {
                                foreach (LIKERT_DEFAULT_OPTIONS as $label) {
                                    $insertOptionStmt->execute([$itemId, $label, $optionOrder]);
                                    $optionOrder++;
                                }
                            }
                        }
                        $itemOrder++;
                    }
                };

                $processItems($resource['item'] ?? []);
            }
            $msg = t($t, 'fhir_import_complete', 'FHIR import complete');
        } else {
            $msg = t($t, 'invalid_file', 'Invalid file');
        }
    } else {
        $msg = t($t, 'no_file_uploaded', 'No file uploaded');
    }
}
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
<meta charset="utf-8">
<title><?=htmlspecialchars(t($t,'manage_questionnaires','Manage Questionnaires'), ENT_QUOTES, 'UTF-8')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<meta name="csrf-token" content="<?=htmlspecialchars(csrf_token(), ENT_QUOTES)?>">
<link rel="manifest" href="<?=asset_url('manifest.webmanifest')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/questionnaire-builder.css')?>">
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js" defer></script>
<script type="module" src="<?=asset_url('assets/js/questionnaire-builder.js')?>" defer></script>
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <?php if ($msg): ?>
    <div class="md-alert"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div>
  <?php endif; ?>
  <div class="md-card md-elev-2">
    <div class="qb-toolbar">
      <button class="md-button md-primary md-elev-2" id="qb-add-questionnaire"><?=t($t,'add_questionnaire','Add Questionnaire')?></button>
      <div class="qb-toolbar-spacer"></div>
      <button class="md-button md-elev-2" id="qb-save" disabled><?=t($t,'save','Save Changes')?></button>
      <button class="md-button md-secondary md-elev-2" id="qb-publish" disabled><?=t($t,'publish','Publish')?></button>
    </div>
    <div id="qb-message" class="qb-message" role="status" aria-live="polite"></div>
    <div id="qb-list" class="qb-list" aria-live="polite"></div>
  </div>

  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'fhir_import','FHIR Import')?></h2>
    <form method="post" enctype="multipart/form-data" class="qb-import-form" action="<?=htmlspecialchars(url_for('admin/questionnaire_manage.php'), ENT_QUOTES, 'UTF-8')?>">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <label class="md-field"><span><?=t($t,'file','File')?></span><input type="file" name="file" required></label>
      <button class="md-button md-elev-2" name="import"><?=t($t,'import','Import')?></button>
    </form>
    <p><?=t($t,'download_xml_template','Download XML template')?>: <a href="<?=htmlspecialchars(asset_url('samples/sample_questionnaire_template.xml'), ENT_QUOTES, 'UTF-8')?>">sample_questionnaire_template.xml</a></p>
  </div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
