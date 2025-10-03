<?php
require_once __DIR__.'/../config.php';
auth_required(['admin']);
$t = load_lang($_SESSION['lang'] ?? 'en');

$alerts = ['success' => [], 'error' => []];

$add_alert = function (string $type, string $message) use (&$alerts): void {
    $alerts[$type][] = $message;
};

function esc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function normalize_text(?string $value): ?string {
    $trimmed = trim((string) $value);
    return $trimmed === '' ? null : $trimmed;
}

function clamp_int(int $value, int $min, int $max): int {
    return max($min, min($max, $value));
}

function fetch_questionnaire(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM questionnaire WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function fetch_section(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM questionnaire_section WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function fetch_item(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM questionnaire_item WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function with_transaction(PDO $pdo, callable $callback): void {
    if ($pdo->inTransaction()) {
        $callback();
        return;
    }

    $pdo->beginTransaction();
    try {
        $callback();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

const ALLOWED_ITEM_TYPES = ['text', 'textarea', 'boolean'];

function item_type_label(string $type): string
{
    switch ($type) {
        case 'textarea':
            return 'Paragraph';
        case 'boolean':
            return 'Yes / No';
        default:
            return 'Text';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    try {
        if (isset($_POST['create_q'])) {
            $title = normalize_text($_POST['title'] ?? '') ?? '';
            if ($title === '') {
                throw new InvalidArgumentException('Questionnaire title is required.');
            }
            $description = normalize_text($_POST['description'] ?? null);

            $stmt = $pdo->prepare('INSERT INTO questionnaire (title, description) VALUES (?, ?)');
            $stmt->execute([$title, $description]);
            $add_alert('success', 'Questionnaire created.');
        } elseif (isset($_POST['create_s'])) {
            $qid = (int) ($_POST['qid'] ?? 0);
            if ($qid <= 0 || !($questionnaire = fetch_questionnaire($pdo, $qid))) {
                throw new InvalidArgumentException('Select a valid questionnaire.');
            }

            $title = normalize_text($_POST['title'] ?? '') ?? '';
            if ($title === '') {
                throw new InvalidArgumentException('Section title is required.');
            }

            $description = normalize_text($_POST['description'] ?? null);
            $orderIndex = max(0, (int) ($_POST['order_index'] ?? 0));

            $stmt = $pdo->prepare('INSERT INTO questionnaire_section (questionnaire_id, title, description, order_index) VALUES (?,?,?,?)');
            $stmt->execute([$qid, $title, $description, $orderIndex]);
            $add_alert('success', 'Section created.');
        } elseif (isset($_POST['create_i'])) {
            $qid = (int) ($_POST['qid'] ?? 0);
            if ($qid <= 0 || !($questionnaire = fetch_questionnaire($pdo, $qid))) {
                throw new InvalidArgumentException('Select a valid questionnaire.');
            }

            $sectionId = isset($_POST['section_id']) && $_POST['section_id'] !== '' ? (int) $_POST['section_id'] : null;
            if ($sectionId !== null) {
                $section = fetch_section($pdo, $sectionId);
                if (!$section || (int) $section['questionnaire_id'] !== $qid) {
                    throw new InvalidArgumentException('Selected section does not belong to the chosen questionnaire.');
                }
            }

            $linkId = normalize_text($_POST['linkId'] ?? '') ?? '';
            $text = normalize_text($_POST['text'] ?? '') ?? '';
            if ($linkId === '' || $text === '') {
                throw new InvalidArgumentException('Both linkId and question text are required.');
            }

            $type = $_POST['type'] ?? 'text';
            if (!in_array($type, ALLOWED_ITEM_TYPES, true)) {
                $type = 'text';
            }

            $orderIndex = max(0, (int) ($_POST['order_index'] ?? 0));
            $weight = clamp_int((int) ($_POST['weight_percent'] ?? 0), 0, 100);

            $stmt = $pdo->prepare('INSERT INTO questionnaire_item (questionnaire_id, section_id, linkId, text, type, order_index, weight_percent) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$qid, $sectionId, $linkId, $text, $type, $orderIndex, $weight]);
            $add_alert('success', 'Item created.');
        } elseif (isset($_POST['import'])) {
            if (empty($_FILES['file']['tmp_name'])) {
                throw new InvalidArgumentException('No file uploaded for import.');
            }

            $raw = file_get_contents($_FILES['file']['tmp_name']);
            if ($raw === false) {
                throw new RuntimeException('Unable to read the uploaded file.');
            }

            $data = null;
            $filename = $_FILES['file']['name'] ?? '';
            if (stripos($filename, '.json') !== false) {
                $data = json_decode($raw, true);
            } else {
                $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA);
                if ($xml !== false) {
                    $json = json_encode($xml);
                    if ($json !== false) {
                        $data = json_decode($json, true);
                    }
                }
            }

            if (!$data) {
                throw new InvalidArgumentException('Uploaded file is not a valid Questionnaire.');
            }

            $questionnairesToImport = [];
            if (($data['resourceType'] ?? '') === 'Bundle') {
                foreach ($data['entry'] ?? [] as $entry) {
                    if (($entry['resource']['resourceType'] ?? '') === 'Questionnaire') {
                        $questionnairesToImport[] = $entry['resource'];
                    }
                }
            } elseif (($data['resourceType'] ?? '') === 'Questionnaire') {
                $questionnairesToImport[] = $data;
            }

            if (!$questionnairesToImport) {
                throw new InvalidArgumentException('No Questionnaire resources found in the uploaded file.');
            }

            foreach ($questionnairesToImport as $resource) {
                $title = normalize_text($resource['title'] ?? '') ?? 'FHIR Questionnaire';
                $description = normalize_text($resource['description'] ?? null);
                $pdo->prepare('INSERT INTO questionnaire (title, description) VALUES (?, ?)')->execute([$title, $description]);
                $newQid = (int) $pdo->lastInsertId();
                $order = 1;
                foreach (($resource['item'] ?? []) as $item) {
                    $itemType = $item['type'] ?? 'text';
                    if (!in_array($itemType, ALLOWED_ITEM_TYPES, true)) {
                        $itemType = 'text';
                    }
                    $itemText = normalize_text($item['text'] ?? ($item['linkId'] ?? 'item')) ?? 'item';
                    $linkId = normalize_text($item['linkId'] ?? ('i' . $order)) ?? ('i' . $order);
                    $pdo->prepare('INSERT INTO questionnaire_item (questionnaire_id, section_id, linkId, text, type, order_index, weight_percent) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$newQid, null, $linkId, $itemText, $itemType, $order, 0]);
                    $order++;
                }
            }

            $add_alert('success', 'FHIR import completed successfully.');
        } elseif (!empty($_POST['action'])) {
            $action = $_POST['action'];
            switch ($action) {
                case 'update_questionnaire':
                    $id = (int) ($_POST['id'] ?? 0);
                    $questionnaire = $id > 0 ? fetch_questionnaire($pdo, $id) : null;
                    if (!$questionnaire) {
                        throw new InvalidArgumentException('Questionnaire not found.');
                    }
                    $title = normalize_text($_POST['title'] ?? '') ?? '';
                    if ($title === '') {
                        throw new InvalidArgumentException('Questionnaire title is required.');
                    }
                    $description = normalize_text($_POST['description'] ?? null);
                    $isActive = isset($_POST['is_active']) && (int) $_POST['is_active'] === 0 ? 0 : 1;

                    $stmt = $pdo->prepare('UPDATE questionnaire SET title = ?, description = ?, is_active = ? WHERE id = ?');
                    $stmt->execute([$title, $description, $isActive, $id]);
                    $add_alert('success', 'Questionnaire updated.');
                    break;

                case 'archive_questionnaire':
                case 'restore_questionnaire':
                    $id = (int) ($_POST['id'] ?? 0);
                    $questionnaire = $id > 0 ? fetch_questionnaire($pdo, $id) : null;
                    if (!$questionnaire) {
                        throw new InvalidArgumentException('Questionnaire not found.');
                    }
                    $targetState = $action === 'archive_questionnaire' ? 0 : 1;
                    with_transaction($pdo, function () use ($pdo, $id, $targetState): void {
                        $pdo->prepare('UPDATE questionnaire SET is_active = ? WHERE id = ?')->execute([$targetState, $id]);
                        $pdo->prepare('UPDATE questionnaire_section SET is_active = ? WHERE questionnaire_id = ?')->execute([$targetState, $id]);
                        $pdo->prepare('UPDATE questionnaire_item SET is_active = ? WHERE questionnaire_id = ?')->execute([$targetState, $id]);
                    });
                    $add_alert('success', $action === 'archive_questionnaire' ? 'Questionnaire archived.' : 'Questionnaire restored.');
                    break;

                case 'update_section':
                    $id = (int) ($_POST['id'] ?? 0);
                    $section = $id > 0 ? fetch_section($pdo, $id) : null;
                    if (!$section) {
                        throw new InvalidArgumentException('Section not found.');
                    }
                    $title = normalize_text($_POST['title'] ?? '') ?? '';
                    if ($title === '') {
                        throw new InvalidArgumentException('Section title is required.');
                    }
                    $description = normalize_text($_POST['description'] ?? null);
                    $orderIndex = max(0, (int) ($_POST['order_index'] ?? 0));
                    $isActive = isset($_POST['is_active']) && (int) $_POST['is_active'] === 0 ? 0 : 1;

                    $stmt = $pdo->prepare('UPDATE questionnaire_section SET title = ?, description = ?, order_index = ?, is_active = ? WHERE id = ?');
                    $stmt->execute([$title, $description, $orderIndex, $isActive, $id]);
                    $add_alert('success', 'Section updated.');
                    break;

                case 'archive_section':
                case 'restore_section':
                    $id = (int) ($_POST['id'] ?? 0);
                    $section = $id > 0 ? fetch_section($pdo, $id) : null;
                    if (!$section) {
                        throw new InvalidArgumentException('Section not found.');
                    }
                    $targetState = $action === 'archive_section' ? 0 : 1;
                    with_transaction($pdo, function () use ($pdo, $id, $targetState): void {
                        $pdo->prepare('UPDATE questionnaire_section SET is_active = ? WHERE id = ?')->execute([$targetState, $id]);
                        $pdo->prepare('UPDATE questionnaire_item SET is_active = ? WHERE section_id = ?')->execute([$targetState, $id]);
                    });
                    $add_alert('success', $action === 'archive_section' ? 'Section archived.' : 'Section restored.');
                    break;

                case 'update_item':
                    $id = (int) ($_POST['id'] ?? 0);
                    $item = $id > 0 ? fetch_item($pdo, $id) : null;
                    if (!$item) {
                        throw new InvalidArgumentException('Item not found.');
                    }

                    $linkId = normalize_text($_POST['linkId'] ?? '') ?? '';
                    $text = normalize_text($_POST['text'] ?? '') ?? '';
                    if ($linkId === '' || $text === '') {
                        throw new InvalidArgumentException('Both linkId and question text are required.');
                    }

                    $type = $_POST['type'] ?? 'text';
                    if (!in_array($type, ALLOWED_ITEM_TYPES, true)) {
                        $type = 'text';
                    }

                    $orderIndex = max(0, (int) ($_POST['order_index'] ?? 0));
                    $weight = clamp_int((int) ($_POST['weight_percent'] ?? 0), 0, 100);
                    $isActive = isset($_POST['is_active']) && (int) $_POST['is_active'] === 0 ? 0 : 1;

                    $sectionId = isset($_POST['section_id']) && $_POST['section_id'] !== '' ? (int) $_POST['section_id'] : null;
                    if ($sectionId !== null) {
                        $section = fetch_section($pdo, $sectionId);
                        if (!$section || (int) $section['questionnaire_id'] !== (int) $item['questionnaire_id']) {
                            throw new InvalidArgumentException('Selected section does not belong to this questionnaire.');
                        }
                    }

                    $stmt = $pdo->prepare('UPDATE questionnaire_item SET linkId = ?, text = ?, type = ?, order_index = ?, weight_percent = ?, section_id = ?, is_active = ? WHERE id = ?');
                    $stmt->execute([$linkId, $text, $type, $orderIndex, $weight, $sectionId, $isActive, $id]);
                    $add_alert('success', 'Item updated.');
                    break;

                case 'archive_item':
                case 'restore_item':
                    $id = (int) ($_POST['id'] ?? 0);
                    $item = $id > 0 ? fetch_item($pdo, $id) : null;
                    if (!$item) {
                        throw new InvalidArgumentException('Item not found.');
                    }
                    $targetState = $action === 'archive_item' ? 0 : 1;
                    $stmt = $pdo->prepare('UPDATE questionnaire_item SET is_active = ? WHERE id = ?');
                    $stmt->execute([$targetState, $id]);
                    $add_alert('success', $action === 'archive_item' ? 'Item archived.' : 'Item restored.');
                    break;

                default:
                    throw new InvalidArgumentException('Unknown action requested.');
            }
        }
    } catch (InvalidArgumentException $e) {
        $add_alert('error', $e->getMessage());
    } catch (Throwable $e) {
        error_log('Questionnaire management error: ' . $e->getMessage());
        $add_alert('error', 'Operation failed. Please try again.');
    }
}

$questionnaires = [];
$sectionsLookup = [];

$questionnaireStmt = $pdo->query('SELECT * FROM questionnaire ORDER BY id DESC');
foreach ($questionnaireStmt as $row) {
    $row['sections'] = [];
    $row['items_without_section'] = [];
    $questionnaires[$row['id']] = $row;
}

if ($questionnaires) {
    $sectionStmt = $pdo->query('SELECT * FROM questionnaire_section ORDER BY questionnaire_id, order_index, id');
    foreach ($sectionStmt as $section) {
        $qid = (int) $section['questionnaire_id'];
        if (!isset($questionnaires[$qid])) {
            continue;
        }
        $section['items'] = [];
        $questionnaires[$qid]['sections'][] = $section;
        $index = array_key_last($questionnaires[$qid]['sections']);
        $sectionsLookup[$section['id']] = &$questionnaires[$qid]['sections'][$index];
    }

    $itemStmt = $pdo->query('SELECT * FROM questionnaire_item ORDER BY questionnaire_id, (section_id IS NULL) DESC, section_id, order_index, id');
    foreach ($itemStmt as $item) {
        $qid = (int) $item['questionnaire_id'];
        if (!isset($questionnaires[$qid])) {
            continue;
        }
        $sectionId = $item['section_id'];
        if ($sectionId && isset($sectionsLookup[$sectionId])) {
            $sectionsLookup[$sectionId]['items'][] = $item;
        } else {
            $questionnaires[$qid]['items_without_section'][] = $item;
        }
    }
}

$csrfToken = csrf_token();
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Questionnaires</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/material.css">
    <link rel="stylesheet" href="/assets/css/styles.css">
    <style>
        .md-alert.success { background:#d4edda; color:#1b5e20; }
        .md-alert.error { background:#f8d7da; color:#b71c1c; }
        .md-card.inactive { opacity:0.75; border-left:4px solid #c62828; }
        .questionnaire-sections { margin-top:1rem; }
        .questionnaire-sections details { margin-bottom:1rem; }
        .questionnaire-sections summary { font-weight:600; }
        .inline-fields { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:0.75rem; }
        .inline-fields .md-field { margin:0; }
        .item-grid { display:grid; gap:0.75rem; margin-top:0.75rem; }
        .item-card { border:1px solid #ccc; border-radius:8px; padding:0.75rem; background:#fafafa; }
        .item-card.inactive { border-color:#e57373; background:#fff5f5; }
        .item-actions { display:flex; flex-wrap:wrap; gap:0.5rem; margin-top:0.5rem; }
        .item-actions button { min-width:120px; }
        .status-tag { display:inline-block; padding:0.1rem 0.5rem; border-radius:12px; font-size:0.75rem; background:#e0e0e0; margin-left:0.5rem; }
        .status-tag.inactive { background:#ffcdd2; color:#b71c1c; }
        summary { cursor:pointer; }
    </style>
</head>
<body class="md-bg">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
    <?php foreach ($alerts as $type => $messages): ?>
        <?php foreach ($messages as $message): ?>
            <div class="md-alert <?=esc($type)?>"><?=esc($message)?></div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="md-card md-elev-2">
        <h2 class="md-card-title">Create Questionnaire</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?=esc($csrfToken)?>">
            <label class="md-field"><span>Title</span><input name="title" required></label>
            <label class="md-field"><span>Description</span><textarea name="description"></textarea></label>
            <button class="md-button md-primary md-elev-2" name="create_q">Create</button>
        </form>
    </div>

    <div class="md-card md-elev-2">
        <h2 class="md-card-title">Add Section</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?=esc($csrfToken)?>">
            <label class="md-field"><span>Questionnaire</span>
                <select name="qid" required>
                    <?php if (!$questionnaires): ?>
                        <option value="">No questionnaires available</option>
                    <?php else: ?>
                        <?php foreach ($questionnaires as $q): ?>
                            <option value="<?= (int) $q['id'] ?>"><?=esc($q['title'])?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </label>
            <label class="md-field"><span>Title</span><input name="title" required></label>
            <label class="md-field"><span>Description</span><textarea name="description"></textarea></label>
            <label class="md-field"><span>Order</span><input type="number" name="order_index" value="1" min="0"></label>
            <button class="md-button md-elev-2" name="create_s">Add Section</button>
        </form>
    </div>

    <div class="md-card md-elev-2">
        <h2 class="md-card-title">Add Item</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?=esc($csrfToken)?>">
            <label class="md-field"><span>Questionnaire</span>
                <select name="qid" required>
                    <?php if (!$questionnaires): ?>
                        <option value="">No questionnaires available</option>
                    <?php else: ?>
                        <?php foreach ($questionnaires as $q): ?>
                            <option value="<?= (int) $q['id'] ?>"><?=esc($q['title'])?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </label>
            <label class="md-field"><span>Section</span>
                <select name="section_id">
                    <option value="">(no section)</option>
                    <?php foreach ($questionnaires as $q): ?>
                        <?php foreach ($q['sections'] as $section): ?>
                            <option value="<?= (int) $section['id'] ?>">Q<?= (int) $q['id'] ?> - <?=esc($section['title'])?></option>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="md-field"><span>linkId</span><input name="linkId" required></label>
            <label class="md-field"><span>Text</span><input name="text" required></label>
            <label class="md-field"><span>Type</span>
                <select name="type">
                    <?php foreach (ALLOWED_ITEM_TYPES as $typeOption): ?>
                        <option value="<?=$typeOption?>"><?=esc(item_type_label($typeOption))?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="md-field"><span>Order</span><input type="number" name="order_index" value="1" min="0"></label>
            <label class="md-field"><span>Weight (%)</span><input type="number" name="weight_percent" value="0" min="0" max="100"></label>
            <button class="md-button md-elev-2" name="create_i">Add Item</button>
        </form>
    </div>

    <div class="md-card md-elev-2">
        <h2 class="md-card-title">FHIR Import</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?=esc($csrfToken)?>">
            <label class="md-field"><span>File</span><input type="file" name="file" required></label>
            <button class="md-button md-elev-2" name="import">Import</button>
        </form>
        <p>Download XML template: <a href="/samples/sample_questionnaire_template.xml">sample_questionnaire_template.xml</a></p>
    </div>

    <?php if (!$questionnaires): ?>
        <div class="md-card md-elev-2">
            <p>No questionnaires created yet.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($questionnaires as $q):
        $qid = (int) $q['id'];
        $qActive = (int) ($q['is_active'] ?? 1) === 1;
    ?>
        <div class="md-card md-elev-2 <?= $qActive ? '' : 'inactive' ?>">
            <h2 class="md-card-title">
                <?=esc($q['title'])?>
                <span class="status-tag <?= $qActive ? '' : 'inactive' ?>"><?= $qActive ? 'Active' : 'Inactive' ?></span>
            </h2>
            <form method="post" class="inline-fields">
                <input type="hidden" name="csrf" value="<?=esc($csrfToken)?>">
                <input type="hidden" name="action" value="update_questionnaire">
                <input type="hidden" name="id" value="<?=$qid?>">
                <label class="md-field"><span>Title</span><input name="title" value="<?=esc($q['title'])?>" required></label>
                <label class="md-field"><span>Description</span><textarea name="description" rows="2"><?=esc($q['description'] ?? '')?></textarea></label>
                <label class="md-field"><span>Status</span>
                    <select name="is_active">
                        <option value="1" <?= $qActive ? 'selected' : '' ?>>Active</option>
                        <option value="0" <?= !$qActive ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </label>
                <div class="item-actions">
                    <button class="md-button md-primary md-elev-2">Save Questionnaire</button>
                </div>
            </form>
            <div class="item-actions">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?=esc($csrfToken)?>">
                    <input type="hidden" name="id" value="<?=$qid?>">
                    <input type="hidden" name="action" value="<?= $qActive ? 'archive_questionnaire' : 'restore_questionnaire' ?>">
                    <button class="md-button md-elev-2" type="submit"><?= $qActive ? 'Archive' : 'Restore' ?> Questionnaire</button>
                </form>
            </div>

            <div class="questionnaire-sections">
                <?php foreach ($q['sections'] as $section):
                    $sid = (int) $section['id'];
                    $sectionActive = (int) ($section['is_active'] ?? 1) === 1;
                ?>
                    <details <?= $sectionActive ? 'open' : '' ?> class="md-card md-elev-1" style="padding:0.75rem;">
                        <summary>
                            <?=esc($section['title'])?>
                            <span class="status-tag <?= $sectionActive ? '' : 'inactive' ?>"><?= $sectionActive ? 'Active' : 'Inactive' ?></span>
                        </summary>
                        <form method="post" class="inline-fields" style="margin-top:0.75rem;">
                            <input type="hidden" name="csrf" value="<?=esc($csrfToken)?>">
                            <input type="hidden" name="action" value="update_section">
                            <input type="hidden" name="id" value="<?=$sid?>">
                            <label class="md-field"><span>Title</span><input name="title" value="<?=esc($section['title'])?>" required></label>
                            <label class="md-field"><span>Description</span><textarea name="description" rows="2"><?=esc($section['description'] ?? '')?></textarea></label>
                            <label class="md-field"><span>Order</span><input type="number" name="order_index" value="<?= (int) $section['order_index'] ?>" min="0" required></label>
                            <label class="md-field"><span>Status</span>
                                <select name="is_active">
                                    <option value="1" <?= $sectionActive ? 'selected' : '' ?>>Active</option>
                                    <option value="0" <?= !$sectionActive ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </label>
                            <div class="item-actions">
                                <button class="md-button md-primary md-elev-2">Save Section</button>
                            </div>
                        </form>
                        <div class="item-actions">
                            <form method="post">
                                <input type="hidden" name="csrf" value="<?=esc($csrfToken)?>">
                                <input type="hidden" name="id" value="<?=$sid?>">
                                <input type="hidden" name="action" value="<?= $sectionActive ? 'archive_section' : 'restore_section' ?>">
                                <button class="md-button md-elev-2" type="submit"><?= $sectionActive ? 'Archive' : 'Restore' ?> Section</button>
                            </form>
                        </div>

                        <div class="item-grid">
                            <?php foreach ($section['items'] as $item):
                                $iid = (int) $item['id'];
                                $itemActive = (int) ($item['is_active'] ?? 1) === 1;
                            ?>
                                <div class="item-card <?= $itemActive ? '' : 'inactive' ?>">
                                    <form method="post">
                                        <input type="hidden" name="csrf" value="<?=esc($csrfToken)?>">
                                        <input type="hidden" name="action" value="update_item">
                                        <input type="hidden" name="id" value="<?=$iid?>">
                                        <label class="md-field"><span>linkId</span><input name="linkId" value="<?=esc($item['linkId'])?>" required></label>
                                        <label class="md-field"><span>Question Text</span><input name="text" value="<?=esc($item['text'])?>" required></label>
                                        <label class="md-field"><span>Type</span>
                                            <select name="type">
                                                <?php foreach (ALLOWED_ITEM_TYPES as $typeOption): ?>
                                                    <option value="<?=$typeOption?>" <?= $item['type'] === $typeOption ? 'selected' : '' ?>><?=esc(item_type_label($typeOption))?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="md-field"><span>Order</span><input type="number" name="order_index" value="<?= (int) $item['order_index'] ?>" min="0" required></label>
                                        <label class="md-field"><span>Weight (%)</span><input type="number" name="weight_percent" min="0" max="100" value="<?= (int) $item['weight_percent'] ?>"></label>
                                        <label class="md-field"><span>Section</span>
                                            <select name="section_id">
                                                <option value="" <?= empty($item['section_id']) ? 'selected' : '' ?>>No section</option>
                                                <?php foreach ($q['sections'] as $sectionOption): ?>
                                                    <option value="<?= (int) $sectionOption['id'] ?>" <?= (int) $item['section_id'] === (int) $sectionOption['id'] ? 'selected' : '' ?>><?=esc($sectionOption['title'])?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="md-field"><span>Status</span>
                                            <select name="is_active">
                                                <option value="1" <?= $itemActive ? 'selected' : '' ?>>Active</option>
                                                <option value="0" <?= !$itemActive ? 'selected' : '' ?>>Inactive</option>
                                            </select>
                                        </label>
                                        <div class="item-actions">
                                            <button class="md-button md-primary md-elev-2">Save Item</button>
                                        </div>
                                    </form>
                                    <form method="post" class="item-actions" style="margin-top:0;">
                                        <input type="hidden" name="csrf" value="<?=esc($csrfToken)?>">
                                        <input type="hidden" name="id" value="<?=$iid?>">
                                        <input type="hidden" name="action" value="<?= $itemActive ? 'archive_item' : 'restore_item' ?>">
                                        <button class="md-button md-elev-2" type="submit"><?= $itemActive ? 'Archive' : 'Restore' ?> Item</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!$section['items']): ?>
                            <p style="margin-top:0.5rem; color:#616161;">No items in this section yet.</p>
                        <?php endif; ?>
                    </details>
                <?php endforeach; ?>

                <?php if (!empty($q['items_without_section'])): ?>
                    <h3>Items without sections</h3>
                    <div class="item-grid">
                        <?php foreach ($q['items_without_section'] as $item):
                            $iid = (int) $item['id'];
                            $itemActive = (int) ($item['is_active'] ?? 1) === 1;
                        ?>
                            <div class="item-card <?= $itemActive ? '' : 'inactive' ?>">
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?=esc($csrfToken)?>">
                                    <input type="hidden" name="action" value="update_item">
                                    <input type="hidden" name="id" value="<?=$iid?>">
                                    <label class="md-field"><span>linkId</span><input name="linkId" value="<?=esc($item['linkId'])?>" required></label>
                                    <label class="md-field"><span>Question Text</span><input name="text" value="<?=esc($item['text'])?>" required></label>
                                    <label class="md-field"><span>Type</span>
                                        <select name="type">
                                            <?php foreach (ALLOWED_ITEM_TYPES as $typeOption): ?>
                                                <option value="<?=$typeOption?>" <?= $item['type'] === $typeOption ? 'selected' : '' ?>><?=esc(item_type_label($typeOption))?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="md-field"><span>Order</span><input type="number" name="order_index" value="<?= (int) $item['order_index'] ?>" min="0" required></label>
                                    <label class="md-field"><span>Weight (%)</span><input type="number" name="weight_percent" min="0" max="100" value="<?= (int) $item['weight_percent'] ?>"></label>
                                    <label class="md-field"><span>Section</span>
                                        <select name="section_id">
                                            <option value="" <?= empty($item['section_id']) ? 'selected' : '' ?>>No section</option>
                                            <?php foreach ($q['sections'] as $sectionOption): ?>
                                                <option value="<?= (int) $sectionOption['id'] ?>" <?= (int) $item['section_id'] === (int) $sectionOption['id'] ? 'selected' : '' ?>><?=esc($sectionOption['title'])?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="md-field"><span>Status</span>
                                        <select name="is_active">
                                            <option value="1" <?= $itemActive ? 'selected' : '' ?>>Active</option>
                                            <option value="0" <?= !$itemActive ? 'selected' : '' ?>>Inactive</option>
                                        </select>
                                    </label>
                                    <div class="item-actions">
                                        <button class="md-button md-primary md-elev-2">Save Item</button>
                                    </div>
                                </form>
                                <form method="post" class="item-actions" style="margin-top:0;">
                                    <input type="hidden" name="csrf" value="<?=esc($csrfToken)?>">
                                    <input type="hidden" name="id" value="<?=$iid?>">
                                    <input type="hidden" name="action" value="<?= $itemActive ? 'archive_item' : 'restore_item' ?>">
                                    <button class="md-button md-elev-2" type="submit"><?= $itemActive ? 'Archive' : 'Restore' ?> Item</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
