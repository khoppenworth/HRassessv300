<?php
require_once __DIR__.'/../config.php';
auth_required(['admin']);
$t = load_lang($_SESSION['lang'] ?? 'en');

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
        ];
        if ($sid) {
            $itemsBySection[$sid][] = $formatted;
        } else {
            $itemsByQuestionnaire[$qid][] = $formatted;
        }
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
    $itemsMap = [];
    foreach ($itemsRows as $row) {
        $qid = (int)$row['questionnaire_id'];
        $itemsMap[$qid][(int)$row['id']] = $row;
    }

    $questionnaireSeen = [];
    $idMap = [
        'questionnaires' => [],
        'sections' => [],
        'items' => [],
    ];

    $pdo->beginTransaction();
    try {
        $insertQuestionnaireStmt = $pdo->prepare('INSERT INTO questionnaire (title, description) VALUES (?, ?)');
        $updateQuestionnaireStmt = $pdo->prepare('UPDATE questionnaire SET title=?, description=? WHERE id=?');

        $insertSectionStmt = $pdo->prepare('INSERT INTO questionnaire_section (questionnaire_id, title, description, order_index) VALUES (?, ?, ?, ?)');
        $updateSectionStmt = $pdo->prepare('UPDATE questionnaire_section SET title=?, description=?, order_index=? WHERE id=?');

        $insertItemStmt = $pdo->prepare('INSERT INTO questionnaire_item (questionnaire_id, section_id, linkId, text, type, order_index, weight_percent) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $updateItemStmt = $pdo->prepare('UPDATE questionnaire_item SET section_id=?, linkId=?, text=?, type=?, order_index=?, weight_percent=? WHERE id=?');

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
                    if (!in_array($type, ['text', 'textarea', 'boolean'], true)) {
                        $type = 'text';
                    }
                    $weight = isset($itemData['weight_percent']) ? (int)$itemData['weight_percent'] : 0;

                    if ($itemId && isset($existingItems[$itemId])) {
                        $updateItemStmt->execute([$sectionId, $linkId, $text, $type, $itemOrder, $weight, $itemId]);
                    } else {
                        $insertItemStmt->execute([$qid, $sectionId, $linkId, $text, $type, $itemOrder, $weight]);
                        $itemId = (int)$pdo->lastInsertId();
                        if ($itemClientId) {
                            $idMap['items'][$itemClientId] = $itemId;
                        }
                        $existingItems[$itemId] = [
                            'id' => $itemId,
                        ];
                    }
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
                if (!in_array($type, ['text', 'textarea', 'boolean'], true)) {
                    $type = 'text';
                }
                $weight = isset($itemData['weight_percent']) ? (int)$itemData['weight_percent'] : 0;

                if ($itemId && isset($existingItems[$itemId])) {
                    $updateItemStmt->execute([null, $linkId, $text, $type, $rootOrder, $weight, $itemId]);
                } else {
                    $insertItemStmt->execute([$qid, null, $linkId, $text, $type, $rootOrder, $weight]);
                    $itemId = (int)$pdo->lastInsertId();
                    if ($itemClientId) {
                        $idMap['items'][$itemClientId] = $itemId;
                    }
                    $existingItems[$itemId] = [
                        'id' => $itemId,
                    ];
                }
                $itemSeen[] = $itemId;
                $rootOrder++;
            }

            $itemsToDelete = array_diff(array_keys($existingItems), $itemSeen);
            if ($itemsToDelete) {
                $placeholders = implode(',', array_fill(0, count($itemsToDelete), '?'));
                $stmt = $pdo->prepare("DELETE FROM questionnaire_item WHERE id IN ($placeholders)");
                $stmt->execute(array_values($itemsToDelete));
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
                $order = 1;
                foreach (($resource['item'] ?? []) as $it) {
                    $type = $it['type'] ?? 'text';
                    $text = $it['text'] ?? ($it['linkId'] ?? 'item');
                    $pdo->prepare('INSERT INTO questionnaire_item (questionnaire_id, section_id, linkId, text, type, order_index, weight_percent) VALUES (?,?,?,?,?,?,?)')
                        ->execute([
                            $qid,
                            null,
                            $it['linkId'] ?? ('i'.$order),
                            $text,
                            in_array($type, ['boolean', 'text', 'textarea'], true) ? $type : 'text',
                            $order,
                            0,
                        ]);
                    $order++;
                }
            }
            $msg = 'FHIR import complete';
        } else {
            $msg = 'Invalid file';
        }
    } else {
        $msg = 'No file';
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Questionnaires</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?=htmlspecialchars(csrf_token(), ENT_QUOTES)?>">
<link rel="stylesheet" href="/assets/css/material.css">
<link rel="stylesheet" href="/assets/css/styles.css">
<link rel="stylesheet" href="/assets/css/questionnaire-builder.css">
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js" defer></script>
<script type="module" src="/assets/js/questionnaire-builder.js" defer></script>
</head>
<body class="md-bg">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <?php if ($msg): ?>
    <div class="md-alert"><?=htmlspecialchars($msg)?></div>
  <?php endif; ?>
  <div class="md-card md-elev-2">
    <div class="qb-toolbar">
      <button class="md-button md-primary md-elev-2" id="qb-add-questionnaire">Add Questionnaire</button>
      <div class="qb-toolbar-spacer"></div>
      <button class="md-button md-elev-2" id="qb-save" disabled>Save</button>
      <button class="md-button md-secondary md-elev-2" id="qb-publish" disabled>Publish</button>
    </div>
    <div id="qb-message" class="qb-message" role="status" aria-live="polite"></div>
    <div id="qb-list" class="qb-list" aria-live="polite"></div>
  </div>

  <div class="md-card md-elev-2">
    <h2 class="md-card-title">FHIR Import</h2>
    <form method="post" enctype="multipart/form-data" class="qb-import-form">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <label class="md-field"><span>File</span><input type="file" name="file" required></label>
      <button class="md-button md-elev-2" name="import">Import</button>
    </form>
    <p>Download XML template: <a href="/samples/sample_questionnaire_template.xml">sample_questionnaire_template.xml</a></p>
  </div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
