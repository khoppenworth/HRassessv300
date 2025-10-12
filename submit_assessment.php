<?php
require_once __DIR__ . '/config.php';
auth_required(['staff','supervisor','admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$err = '';
$flashNotice = '';
$cfg = get_site_config($pdo);

$user = current_user();
$questionnaireSql = "SELECT DISTINCT q.id, q.title FROM questionnaire q";
try {
    if ($user['role'] === 'staff') {
        $questionnaireSql .= " JOIN questionnaire_work_function qw ON qw.questionnaire_id = q.id WHERE qw.work_function = :wf";
        $questionnaireSql .= " ORDER BY q.title";
        $stmt = $pdo->prepare($questionnaireSql);
        $stmt->execute(['wf' => $user['work_function']]);
        $q = $stmt->fetchAll();
    } else {
        $q = $pdo->query("SELECT id, title FROM questionnaire ORDER BY title")->fetchAll();
    }
} catch (PDOException $e) {
    error_log('submit_assessment questionnaire lookup failed: ' . $e->getMessage());
    $fallback = $pdo->query('SELECT id, title FROM questionnaire ORDER BY title');
    $q = $fallback ? $fallback->fetchAll() : [];
}
$periods = $pdo->query("SELECT id, label FROM performance_period ORDER BY period_start DESC")->fetchAll();
$qid = (int)($_GET['qid'] ?? ($q[0]['id'] ?? 0));
$availableQuestionnaireIds = array_map(static fn($row) => (int)$row['id'], $q);
if ($qid && !in_array($qid, $availableQuestionnaireIds, true)) {
    $qid = $availableQuestionnaireIds[0] ?? 0;
}
$periodId = (int)($_GET['performance_period_id'] ?? ($periods[0]['id'] ?? 0));

$draftSaved = $_GET['saved'] ?? '';
if ($draftSaved === 'draft') {
    $flashNotice = t($t, 'draft_saved', 'Draft saved. You can return to this questionnaire from the same performance period to continue editing.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $qid = (int)($_POST['qid'] ?? 0);
    $periodId = (int)($_POST['performance_period_id'] ?? 0);
    $action = $_POST['action'] ?? 'submit_final';
    $isDraftSave = ($action === 'save_draft');
    if (!$periodId) {
        $err = t($t,'select_period','Please select a performance period.');
    } else {
        $existingStmt = $pdo->prepare('SELECT * FROM questionnaire_response WHERE user_id=? AND questionnaire_id=? AND performance_period_id=?');
        $existingStmt->execute([$user['id'], $qid, $periodId]);
        $existingResponse = $existingStmt->fetch();
        if ($existingResponse && !$isDraftSave && ($existingResponse['status'] ?? '') !== 'draft') {
            $err = t($t,'duplicate_submission','A submission already exists for the selected performance period.');
        } else {
        $pdo->beginTransaction();
        try {
            $responseId = $existingResponse ? (int)$existingResponse['id'] : 0;
            $statusValue = $isDraftSave ? 'draft' : 'submitted';
            $scoreValue = $isDraftSave ? null : 0;
            if ($existingResponse) {
                $updateStmt = $pdo->prepare('UPDATE questionnaire_response SET status=?, score=?, created_at=NOW(), reviewed_by=NULL, reviewed_at=NULL, review_comment=NULL WHERE id=?');
                $updateStmt->execute([$statusValue, $scoreValue, $responseId]);
                $pdo->prepare('DELETE FROM questionnaire_response_item WHERE response_id=?')->execute([$responseId]);
            } else {
                $insertStmt = $pdo->prepare('INSERT INTO questionnaire_response (user_id, questionnaire_id, performance_period_id, status, created_at) VALUES (?,?,?,?, NOW())');
                $insertStmt->execute([$user['id'], $qid, $periodId, $statusValue]);
                $responseId = (int)$pdo->lastInsertId();
            }

            // Fetch items with weights
            $itemsStmt = $pdo->prepare('SELECT id, linkId, type, allow_multiple, COALESCE(weight_percent,0) AS weight_percent, is_required FROM questionnaire_item WHERE questionnaire_id=? ORDER BY order_index ASC');
            $itemsStmt->execute([$qid]);
            $items = $itemsStmt->fetchAll();

            $optionMap = [];
            if ($items) {
                $itemIds = array_column($items, 'id');
                $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
                $optStmt = $pdo->prepare("SELECT questionnaire_item_id, value FROM questionnaire_item_option WHERE questionnaire_item_id IN ($placeholders) ORDER BY questionnaire_item_id, order_index, id");
                $optStmt->execute($itemIds);
                foreach ($optStmt->fetchAll() as $opt) {
                    $itemId = (int)$opt['questionnaire_item_id'];
                    $optionMap[$itemId][] = $opt['value'];
                }
            }

            $score_sum = 0.0;
            $weight_sum = 0.0;

            $missingRequired = [];
            foreach ($items as $it) {
                $name = 'item_' . $it['linkId'];
                $weight = (float)$it['weight_percent'];
                $achieved = 0.0;
                $a = json_encode([]);
                $isRequired = !empty($it['is_required']);
                $questionTitle = trim((string)($it['text'] ?? ''));
                if ($questionTitle === '') {
                    $questionTitle = (string)($it['linkId'] ?? '');
                }
                $hasResponse = false;

                if ($it['type'] === 'boolean') {
                    $hasResponse = array_key_exists($name, $_POST);
                    $ans = $_POST[$name] ?? '';
                    $val = ($ans === '1' || $ans === 'true' || $ans === 'on') ? 'true' : 'false';
                    $achieved = ($val === 'true') ? $weight : 0.0;
                    $a = json_encode([['valueBoolean' => $val === 'true']]);
                } elseif ($it['type'] === 'likert') {
                    $raw = $_POST[$name] ?? '';
                    if (is_array($raw)) {
                        $raw = reset($raw);
                    }
                    $selected = is_string($raw) ? trim($raw) : '';
                    $validOptions = array_map('trim', $optionMap[(int)$it['id']] ?? []);
                    if ($selected !== '' && $validOptions && !in_array($selected, $validOptions, true)) {
                        $selected = '';
                    }
                    $scoreValue = null;
                    if ($selected !== '') {
                        if (preg_match('/^([1-5])/', $selected, $matches)) {
                            $scoreValue = (int)$matches[1];
                        } elseif (is_numeric($selected)) {
                            $candidate = (int)$selected;
                            if ($candidate >= 1 && $candidate <= 5) {
                                $scoreValue = $candidate;
                            }
                        }
                    }
                    if ($scoreValue !== null) {
                        $achieved = $weight > 0 ? ($weight * $scoreValue / 5.0) : 0.0;
                    }
                    if ($selected !== '') {
                        $hasResponse = true;
                        $answerEntry = [];
                        if ($scoreValue !== null) {
                            $answerEntry['valueInteger'] = $scoreValue;
                        }
                        $answerEntry['valueString'] = $selected;
                        $a = json_encode([$answerEntry]);
                    }
                } elseif ($it['type'] === 'choice') {
                    $allowMultiple = !empty($it['allow_multiple']);
                    $raw = $_POST[$name] ?? ($allowMultiple ? [] : '');
                    $selected = $allowMultiple ? (array)$raw : [$raw];
                    $values = array_values(array_filter(array_map(static function ($val) {
                        if (is_string($val)) {
                            return trim($val);
                        }
                        return '';
                    }, $selected), static fn($val) => $val !== ''));
                    $validOptions = array_map('trim', $optionMap[(int)$it['id']] ?? []);
                    if ($validOptions) {
                        $values = array_values(array_filter($values, static function ($val) use ($validOptions) {
                            return in_array($val, $validOptions, true);
                        }));
                    }
                    if ($values) {
                        $hasResponse = true;
                    }
                    $achieved = $values ? $weight : 0.0;
                    $a = json_encode(array_map(static fn($val) => ['valueString' => $val], $values));
                } else {
                    $ans = $_POST[$name] ?? '';
                    $txt = trim((string)$ans);
                    if ($txt !== '') {
                        $hasResponse = true;
                    }
                    $achieved = $txt !== '' ? $weight : 0.0;
                    $a = json_encode([['valueString' => $txt]]);
                }

                if ($isRequired && !$isDraftSave && !$hasResponse) {
                    $missingRequired[] = $questionTitle;
                }

                $ins = $pdo->prepare('INSERT INTO questionnaire_response_item (response_id, linkId, answer) VALUES (?,?,?)');
                $ins->execute([$responseId, $it['linkId'], $a]);

                if (!$isDraftSave) {
                    $score_sum += $achieved;
                    $weight_sum += $weight;
                }
            }
            if (!$isDraftSave && $missingRequired) {
                $pdo->rollBack();
                $err = t($t, 'required_questions_missing', 'Please complete all required questions before submitting.');
                if (count($missingRequired) <= 5) {
                    $err .= ' ' . t($t, 'missing_questions_list', 'Missing:') . ' ' . implode(', ', array_map(static function ($label) use ($t) {
                        return $label !== '' ? $label : t($t, 'question', 'Question');
                    }, $missingRequired));
                }
            } else {
                if ($isDraftSave) {
                    $pdo->prepare('UPDATE questionnaire_response SET score=NULL WHERE id=?')->execute([$responseId]);
                } else {
                    $pct = $weight_sum > 0 ? (int)round(($score_sum / $weight_sum) * 100) : null;
                    $pdo->prepare('UPDATE questionnaire_response SET score=? WHERE id=?')->execute([$pct, $responseId]);
                }
                $pdo->commit();
                if ($isDraftSave) {
                    $query = http_build_query([
                        'qid' => $qid,
                        'performance_period_id' => $periodId,
                        'saved' => 'draft',
                    ]);
                    header('Location: ' . url_for('submit_assessment.php?' . $query));
                } else {
                    header('Location: ' . url_for('my_performance.php?msg=submitted'));
                }
                exit;
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('submit_assessment failed: ' . $e->getMessage());
            $err = t($t, 'submission_failed', 'We could not save your responses. Please try again.');
        }
        }
    }
}

// Load selected questionnaire with sections and items
$questionnaireDetails = null;
$sections = [];
$items = [];
$availablePeriods = $periods;
$taken = [];
$finalizedPeriods = [];
$draftMap = [];
$currentAnswers = [];
$currentResponse = null;
if ($qid) {
    $detailStmt = $pdo->prepare('SELECT id, title, description FROM questionnaire WHERE id=?');
    $detailStmt->execute([$qid]);
    $questionnaireDetails = $detailStmt->fetch() ?: null;
    $s = $pdo->prepare("SELECT * FROM questionnaire_section WHERE questionnaire_id=? ORDER BY order_index ASC");
    $s->execute([$qid]); $sections = $s->fetchAll();
    $i = $pdo->prepare("SELECT * FROM questionnaire_item WHERE questionnaire_id=? ORDER BY order_index ASC");
    $i->execute([$qid]);
    $items = $i->fetchAll();
    $itemOptions = [];
    if ($items) {
        $itemIds = array_column($items, 'id');
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $optStmt = $pdo->prepare("SELECT questionnaire_item_id, value, order_index FROM questionnaire_item_option WHERE questionnaire_item_id IN ($placeholders) ORDER BY questionnaire_item_id, order_index, id");
        $optStmt->execute($itemIds);
        foreach ($optStmt->fetchAll() as $row) {
            $itemOptions[(int)$row['questionnaire_item_id']][] = $row;
        }
        foreach ($items as &$itemRow) {
            $itemId = (int)$itemRow['id'];
            $itemRow['options'] = $itemOptions[$itemId] ?? [];
            $itemRow['allow_multiple'] = (bool)$itemRow['allow_multiple'];
        }
        unset($itemRow);
    }
    $takenStmt = $pdo->prepare('SELECT performance_period_id, status, created_at, id FROM questionnaire_response WHERE user_id=? AND questionnaire_id=? ORDER BY created_at DESC');
    $takenStmt->execute([$user['id'], $qid]);
    $takenRows = $takenStmt->fetchAll();
    $finalStatuses = ['submitted','approved','rejected'];
    foreach ($takenRows as $row) {
        $pid = (int)$row['performance_period_id'];
        $status = $row['status'] ?? 'submitted';
        if (in_array($status, $finalStatuses, true)) {
            $finalizedPeriods[$pid] = true;
        }
        if ($status === 'draft') {
            $draftMap[$pid] = $row;
        }
    }
    $availablePeriods = array_values(array_filter($periods, static function ($p) use ($finalizedPeriods, $draftMap) {
        $pid = (int)$p['id'];
        return !isset($finalizedPeriods[$pid]) || isset($draftMap[$pid]);
    }));
    if ($periodId && isset($finalizedPeriods[$periodId]) && !isset($draftMap[$periodId])) {
        $periodId = $availablePeriods[0]['id'] ?? 0;
    }
    if (!$periodId && $availablePeriods) {
        $periodId = $availablePeriods[0]['id'];
    }
    if ($periodId && isset($draftMap[$periodId])) {
        $currentResponse = $draftMap[$periodId];
        $answerStmt = $pdo->prepare('SELECT linkId, answer FROM questionnaire_response_item WHERE response_id=?');
        $answerStmt->execute([(int)$currentResponse['id']]);
        foreach ($answerStmt->fetchAll() as $answerRow) {
            $decoded = json_decode($answerRow['answer'] ?? '[]', true);
            $currentAnswers[$answerRow['linkId']] = is_array($decoded) ? $decoded : [];
        }
        if ($flashNotice === '' && !empty($currentResponse['created_at'])) {
            $savedAt = date('F j, Y g:i a', strtotime($currentResponse['created_at']));
            $template = t($t, 'editing_draft_from', 'You are editing a saved draft from %s.');
            $flashNotice = sprintf($template, $savedAt);
        }
    }
}

$renderQuestionField = static function (array $it, array $t, array $answers): string {
    $options = $it['options'] ?? [];
    $allowMultiple = !empty($it['allow_multiple']);
    $linkId = (string)($it['linkId'] ?? '');
    $answerEntries = $answers[$linkId] ?? [];
    $checkedValues = [];
    if (is_array($answerEntries)) {
        foreach ($answerEntries as $entry) {
            if (is_array($entry)) {
                foreach (['valueString','valueCoding','valueInteger','valueBoolean'] as $key) {
                    if (isset($entry[$key])) {
                        $checkedValues[] = $entry[$key];
                    }
                }
            }
        }
    }
    $firstValue = $checkedValues[0] ?? '';
    $required = !empty($it['is_required']);
    $fieldClass = 'md-field' . ($required ? ' md-field--required' : '');
    $requiredAttr = $required ? ' required' : '';
    $ariaRequired = $required ? ' aria-required="true"' : '';
    ob_start();
    ?>
    <label class="<?=htmlspecialchars($fieldClass, ENT_QUOTES, 'UTF-8')?>">
      <span><?=htmlspecialchars($it['text'] ?? '', ENT_QUOTES, 'UTF-8')?></span>
      <?php if (($it['type'] ?? '') === 'boolean'): ?>
        <?php $isChecked = false;
        if ($answerEntries) {
            $entry = $answerEntries[0] ?? [];
            if (is_array($entry)) {
                if (array_key_exists('valueBoolean', $entry)) {
                    $isChecked = filter_var($entry['valueBoolean'], FILTER_VALIDATE_BOOLEAN);
                } elseif (array_key_exists('valueString', $entry)) {
                    $isChecked = filter_var($entry['valueString'], FILTER_VALIDATE_BOOLEAN);
                }
            }
        }
        ?>
        <input type="checkbox" name="item_<?=htmlspecialchars($it['linkId'] ?? '', ENT_QUOTES, 'UTF-8')?>" value="true" <?=$isChecked ? 'checked' : ''?><?=$requiredAttr?>>
      <?php elseif (($it['type'] ?? '') === 'textarea'): ?>
        <textarea name="item_<?=htmlspecialchars($it['linkId'] ?? '', ENT_QUOTES, 'UTF-8')?>" rows="3"<?=$requiredAttr?>><?php
            $textValue = '';
            if ($answerEntries) {
                $entry = $answerEntries[0] ?? [];
                if (is_array($entry) && isset($entry['valueString'])) {
                    $textValue = (string)$entry['valueString'];
                }
            }
            echo htmlspecialchars($textValue, ENT_QUOTES, 'UTF-8');
        ?></textarea>
      <?php elseif (($it['type'] ?? '') === 'likert' && !empty($options)): ?>
        <div class="likert-scale" role="radiogroup" aria-label="<?=htmlspecialchars($it['text'] ?? '', ENT_QUOTES, 'UTF-8')?>"<?=$ariaRequired?>>
          <?php foreach ($options as $idx => $opt):
            $value = $opt['value'] ?? (string)($idx + 1);
            $label = $opt['value'] ?? ('Option ' . ($idx + 1));
            $inputId = ($it['linkId'] ?? 'likert') . '_' . ($idx + 1);
            $selected = is_string($firstValue) ? $firstValue : ((string)$firstValue);
          ?>
          <label class="likert-scale__option" for="<?=htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8')?>">
            <input type="radio" id="<?=htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8')?>" name="item_<?=htmlspecialchars($it['linkId'] ?? '', ENT_QUOTES, 'UTF-8')?>" value="<?=htmlspecialchars($value, ENT_QUOTES, 'UTF-8')?>" <?=($selected !== '' && (string)$value === $selected) ? 'checked' : ''?><?=($required && $idx === 0) ? ' required' : ''?>>
            <span><?=htmlspecialchars($label, ENT_QUOTES, 'UTF-8')?></span>
          </label>
          <?php endforeach; ?>
        </div>
      <?php elseif (($it['type'] ?? '') === 'choice' && !empty($options)): ?>
        <?php if ($allowMultiple): ?>
        <select name="item_<?=htmlspecialchars($it['linkId'] ?? '', ENT_QUOTES, 'UTF-8')?>[]" multiple size="<?=max(3, min(6, count($options)))?>"<?=$requiredAttr?>>
          <?php foreach ($options as $opt): ?>
            <?php $optValue = (string)($opt['value'] ?? '');
            $isSelected = in_array($optValue, array_map('strval', $checkedValues), true);
            ?>
            <option value="<?=htmlspecialchars($optValue, ENT_QUOTES, 'UTF-8')?>" <?=$isSelected ? 'selected' : ''?>><?=htmlspecialchars($opt['value'] ?? '', ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
          <small class="md-hint"><?=htmlspecialchars(t($t,'multiple_choice_hint','Select all that apply'), ENT_QUOTES, 'UTF-8')?></small>
      <?php else: ?>
        <select name="item_<?=htmlspecialchars($it['linkId'] ?? '', ENT_QUOTES, 'UTF-8')?>"<?=$requiredAttr?>>
            <option value=""><?=htmlspecialchars(t($t,'select_single_option','Select an option'), ENT_QUOTES, 'UTF-8')?></option>
          <?php foreach ($options as $opt): ?>
            <?php $optValue = (string)($opt['value'] ?? '');
            $isSelected = (string)$optValue !== '' && ((string)$optValue === (string)$firstValue);
            ?>
            <option value="<?=htmlspecialchars($optValue, ENT_QUOTES, 'UTF-8')?>" <?=$isSelected ? 'selected' : ''?>><?=htmlspecialchars($opt['value'] ?? '', ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <?php else: ?>
        <?php
        $textValue = '';
        if ($answerEntries) {
            $entry = $answerEntries[0] ?? [];
            if (is_array($entry)) {
                if (isset($entry['valueString'])) {
                    $textValue = (string)$entry['valueString'];
                } elseif (isset($entry['valueInteger'])) {
                    $textValue = (string)$entry['valueInteger'];
                }
            }
        }
        ?>
        <input name="item_<?=htmlspecialchars($it['linkId'] ?? '', ENT_QUOTES, 'UTF-8')?>" value="<?=htmlspecialchars($textValue, ENT_QUOTES, 'UTF-8')?>"<?=$requiredAttr?>>
      <?php endif; ?>
      <?php if (isset($it['weight_percent']) && $it['weight_percent'] !== null): ?>
        <small class="md-hint">Weight: <?= (int)$it['weight_percent']?>%</small>
      <?php endif; ?>
    </label>
    <?php
    return ob_get_clean();
};
?>
<!doctype html><html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>"><head>
<meta charset="utf-8"><title><?=htmlspecialchars(t($t,'submit_assessment','Submit Assessment'), ENT_QUOTES, 'UTF-8')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<link rel="manifest" href="<?=asset_url('manifest.webmanifest')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head><body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/templates/header.php'; ?>
<section class="md-section">
<div class="md-card md-elev-2">
  <h2 class="md-card-title"><?=t($t,'submit_assessment','Submit Assessment')?></h2>
  <?php if ($flashNotice): ?><div class="md-alert success"><?=htmlspecialchars($flashNotice, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
  <?php if (!empty($err)): ?><div class="md-alert error"><?=htmlspecialchars($err, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
  <form method="get" class="md-inline-form" action="<?=htmlspecialchars(url_for('submit_assessment.php'), ENT_QUOTES, 'UTF-8')?>" data-questionnaire-form>
    <label class="md-field">
      <span><?=t($t,'select_questionnaire','Select questionnaire')?></span>
      <select name="qid" data-questionnaire-select>
        <?php foreach ($q as $row): ?>
          <option value="<?=$row['id']?>" <?=($row['id']==$qid?'selected':'')?>><?=htmlspecialchars($row['title'])?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="md-field">
      <span><?=t($t,'performance_period','Performance Period')?></span>
      <select name="performance_period_id" data-performance-period-select>
        <?php foreach ($periods as $period): ?>
          <?php
            $disabled = isset($finalizedPeriods[$period['id']]) && !isset($draftMap[$period['id']]);
            $labelSuffix = '';
            if ($disabled) {
                $labelSuffix = ' · ' . t($t,'already_submitted','Submitted');
            } elseif (isset($draftMap[$period['id']])) {
                $labelSuffix = ' · ' . t($t,'status_draft','Draft');
            }
          ?>
          <option value="<?=$period['id']?>" <?=($period['id']===$periodId?'selected':'')?> <?=$disabled?'disabled':''?>><?=htmlspecialchars($period['label'])?><?=$labelSuffix?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <noscript>
      <button type="submit" class="md-button md-secondary"><?=t($t,'submit','Submit')?></button>
    </noscript>
  </form>
  <?php if ($qid && empty($availablePeriods)): ?>
    <p><?=t($t,'all_periods_used','You have already submitted for every period available for this questionnaire.')?></p>
  <?php elseif ($qid): ?>
  <form method="post" action="<?=htmlspecialchars(url_for('submit_assessment.php'), ENT_QUOTES, 'UTF-8')?>" id="assessment-form" class="md-assessment-form">
    <input type="hidden" name="csrf" value="<?=csrf_token()?>">
    <input type="hidden" name="qid" value="<?=$qid?>">
    <input type="hidden" name="performance_period_id" value="<?=$periodId?>">
    <?php if ($questionnaireDetails): ?>
      <div class="md-questionnaire-header">
        <h3 class="md-section-title"><?=htmlspecialchars($questionnaireDetails['title'])?></h3>
        <?php if (!empty($questionnaireDetails['description'])): ?>
          <p class="md-muted"><?=htmlspecialchars($questionnaireDetails['description'])?></p>
        <?php endif; ?>
        <div class="md-divider"></div>
      </div>
    <?php endif; ?>
    <?php foreach ($sections as $sec): ?>
      <h3 class="md-section-title"><?=htmlspecialchars($sec['title'])?></h3>
      <p class="md-muted"><?=htmlspecialchars($sec['description'])?></p>
      <div class="md-divider"></div>
      <?php foreach ($items as $it): if ((int)$it['section_id'] !== (int)$sec['id']) continue; ?>
        <?=$renderQuestionField($it, $t, $currentAnswers ?? [])?>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <?php $renderedRoot = false; ?>
    <?php foreach ($items as $it): if ($it['section_id'] !== null) continue; ?>
      <?php if (!$renderedRoot): ?>
        <h3 class="md-section-title"><?=htmlspecialchars(t($t,'additional_items','Additional questions'), ENT_QUOTES, 'UTF-8')?></h3>
        <div class="md-divider"></div>
        <?php $renderedRoot = true; ?>
      <?php endif; ?>
      <?=$renderQuestionField($it, $t, $currentAnswers ?? [])?>
    <?php endforeach; ?>
    <div class="md-form-actions md-form-actions--stack">
      <button class="md-button md-outline md-floating-save-draft" name="action" value="save_draft" type="submit" formnovalidate><?=t($t,'save_draft','Save Draft')?></button>
      <button class="md-button md-primary md-elev-2" name="action" value="submit_final" type="submit"><?=t($t,'submit','Submit')?></button>
    </div>
  </form>
  <?php else: ?>
    <p><?=t($t,'no_questionnaire','No questionnaire found.')?></p>
  <?php endif; ?>
  <script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
  (function() {
    const form = document.querySelector('[data-questionnaire-form]');
    if (!form) {
      return;
    }
    const questionnaireSelect = form.querySelector('[data-questionnaire-select]');
    const periodSelect = form.querySelector('[data-performance-period-select]');

    const updateLocation = () => {
      const currentUrl = new URL(window.location.href);
      const action = form.getAttribute('action');
      if (action) {
        const actionUrl = new URL(action, window.location.origin);
        currentUrl.pathname = actionUrl.pathname;
      }

      const qid = questionnaireSelect ? questionnaireSelect.value : '';
      if (qid) {
        currentUrl.searchParams.set('qid', qid);
      } else {
        currentUrl.searchParams.delete('qid');
      }

      if (periodSelect && periodSelect.options.length) {
        const selectedOption = periodSelect.options[periodSelect.selectedIndex] || null;
        if (selectedOption && !selectedOption.disabled && selectedOption.value !== '') {
          currentUrl.searchParams.set('performance_period_id', selectedOption.value);
        } else {
          currentUrl.searchParams.delete('performance_period_id');
        }
      } else {
        currentUrl.searchParams.delete('performance_period_id');
      }

      window.location.assign(currentUrl.toString());
    };

    if (questionnaireSelect) {
      questionnaireSelect.addEventListener('change', () => {
        if (periodSelect) {
          periodSelect.selectedIndex = -1;
        }
        updateLocation();
      });
    }

    if (periodSelect) {
      periodSelect.addEventListener('change', () => {
        updateLocation();
      });
    }
  })();
  </script>
</div>
</section>
<?php include __DIR__.'/templates/footer.php'; ?>
</body></html>