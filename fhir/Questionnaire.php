<?php
require_once __DIR__.'/utils.php';
$entries = [];
$qs = $pdo->query("SELECT * FROM questionnaire ORDER BY id DESC");
foreach ($qs as $q) {
  $items = $pdo->prepare("SELECT linkId, text, type, COALESCE(weight_percent,0) AS weight_percent FROM questionnaire_item WHERE questionnaire_id=? ORDER BY order_index ASC");
  $items->execute([$q['id']]);
  $entries[] = ["resource"=>[
    "resourceType"=>"Questionnaire",
    "id"=>$q['id'],
    "title"=>$q['title'],
    "description"=>$q['description'],
    "item"=>array_map(function($it){ return ["linkId"=>$it['linkId'],"text"=>$it['text'],"type"=>$it['type'],"extension"=>[["url"=>"http://example.org/fhir/StructureDefinition/weightPercent","valueInteger"=>(int)$it['weight_percent']]]]; }, $items->fetchAll())
  ]];
}
echo json_encode(bundle($entries));
?>