<?php
require_once __DIR__.'/../config.php';
auth_required(['admin']);
$t = load_lang($_SESSION['lang'] ?? 'en');
$msg='';

if (isset($_POST['create_q'])) { csrf_check();
  $stm=$pdo->prepare("INSERT INTO questionnaire (title, description) VALUES (?,?)");
  $stm->execute([$_POST['title'], $_POST['description']]);
  $msg='Questionnaire created';
}
if (isset($_POST['create_s'])) { csrf_check();
  $stm=$pdo->prepare("INSERT INTO questionnaire_section (questionnaire_id,title,description,order_index) VALUES (?,?,?,?)");
  $stm->execute([$_POST['qid'], $_POST['title'], $_POST['description'], (int)$_POST['order_index']]);
  $msg='Section created';
}
if (isset($_POST['create_i'])) { csrf_check();
  $stm=$pdo->prepare("INSERT INTO questionnaire_item (questionnaire_id,section_id,linkId,text,type,order_index,weight_percent) VALUES (?,?,?,?,?,?,?)");
  $sec = $_POST['section_id'] ?: null;
  $w = (int)$_POST['weight_percent'];
  $stm->execute([$_POST['qid'], $sec, $_POST['linkId'], $_POST['text'], $_POST['type'], (int)$_POST['order_index'], $w]);
  $msg='Item created';
}

# Import FHIR JSON/XML (same as before, weight default 0)
if (isset($_POST['import'])) { csrf_check();
  if (!empty($_FILES['file']['tmp_name'])) {
    $raw = file_get_contents($_FILES['file']['tmp_name']);
    $data = null;
    if (stripos($_FILES['file']['name'], '.json') !== false) {
        $data = json_decode($raw, true);
    } else {
        $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA);
        $json = json_encode($xml);
        $data = json_decode($json, true);
    }
    if ($data) {
        $qs = [];
        if (($data['resourceType'] ?? '') === 'Bundle') {
            foreach ($data['entry'] ?? [] as $e) {
                if (($e['resource']['resourceType'] ?? '') === 'Questionnaire') $qs[] = $e['resource'];
            }
        } elseif (($data['resourceType'] ?? '') === 'Questionnaire') {
            $qs[] = $data;
        }
        foreach ($qs as $qq) {
            $pdo->prepare("INSERT INTO questionnaire (title, description) VALUES (?,?)")->execute([$qq['title'] ?? 'FHIR Questionnaire', $qq['description'] ?? null]);
            $qid = (int)$pdo->lastInsertId();
            $order = 1;
            foreach (($qq['item'] ?? []) as $it) {
                $type = $it['type'] ?? 'text';
                $text = $it['text'] ?? ($it['linkId'] ?? 'item');
                $pdo->prepare("INSERT INTO questionnaire_item (questionnaire_id, section_id, linkId, text, type, order_index, weight_percent) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$qid, null, $it['linkId'] ?? ('i'.$order), $text, in_array($type,['boolean','text','textarea'])?$type:'text', $order, 0]);
                $order++;
            }
        }
        $msg = 'FHIR import complete';
    } else $msg='Invalid file';
  } else $msg='No file';
}

$qs = $pdo->query("SELECT * FROM questionnaire ORDER BY id DESC")->fetchAll();
$sections = $pdo->query("SELECT * FROM questionnaire_section ORDER BY questionnaire_id, order_index")->fetchAll();
$items = $pdo->query("SELECT * FROM questionnaire_item ORDER BY questionnaire_id, order_index")->fetchAll();
?>
<!doctype html><html><head>
<meta charset="utf-8"><title>Questionnaires</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/material.css">
<link rel="stylesheet" href="/assets/css/styles.css">
</head><body class="md-bg">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
<?php if ($msg): ?><div class="md-alert"><?=$msg?></div><?php endif; ?>

<div class="md-card md-elev-2">
  <h2 class="md-card-title">Create Questionnaire</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=csrf_token()?>">
    <label class="md-field"><span>Title</span><input name="title" required></label>
    <label class="md-field"><span>Description</span><textarea name="description"></textarea></label>
    <button class="md-button md-primary md-elev-2" name="create_q">Create</button>
  </form>
</div>

<div class="md-card md-elev-2">
  <h2 class="md-card-title">Add Section</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=csrf_token()?>">
    <label class="md-field"><span>Questionnaire</span>
      <select name="qid"><?php foreach ($qs as $q): ?><option value="<?=$q['id']?>"><?=$q['title']?></option><?php endforeach; ?></select>
    </label>
    <label class="md-field"><span>Title</span><input name="title" required></label>
    <label class="md-field"><span>Description</span><textarea name="description"></textarea></label>
    <label class="md-field"><span>Order</span><input type="number" name="order_index" value="1"></label>
    <button class="md-button md-elev-2" name="create_s">Add Section</button>
  </form>
</div>

<div class="md-card md-elev-2">
  <h2 class="md-card-title">Add Item</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=csrf_token()?>">
    <label class="md-field"><span>Questionnaire</span>
      <select name="qid"><?php foreach ($qs as $q): ?><option value="<?=$q['id']?>"><?=$q['title']?></option><?php endforeach; ?></select>
    </label>
    <label class="md-field"><span>Section</span>
      <select name="section_id"><option value="">(no section)</option>
        <?php foreach ($sections as $s): ?><option value="<?=$s['id']?>">Q<?=$s['questionnaire_id']?> - <?=$s['title']?></option><?php endforeach; ?>
      </select>
    </label>
    <label class="md-field"><span>linkId</span><input name="linkId" required></label>
    <label class="md-field"><span>Text</span><input name="text" required></label>
    <label class="md-field"><span>Type</span><select name="type"><option>text</option><option>textarea</option><option>boolean</option></select></label>
    <label class="md-field"><span>Order</span><input type="number" name="order_index" value="1"></label>
    <label class="md-field"><span>Weight (%)</span><input type="number" name="weight_percent" value="0" min="0" max="100"></label>
    <button class="md-button md-elev-2" name="create_i">Add Item</button>
  </form>
</div>

<div class="md-card md-elev-2">
  <h2 class="md-card-title">FHIR Import</h2>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?=csrf_token()?>">
    <label class="md-field"><span>File</span><input type="file" name="file" required></label>
    <button class="md-button md-elev-2" name="import">Import</button>
  </form>
  <p>Download XML template: <a href="/samples/sample_questionnaire_template.xml">sample_questionnaire_template.xml</a></p>
</div>

</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body></html>