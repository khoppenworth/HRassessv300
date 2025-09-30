<?php
require_once __DIR__.'/utils.php';
echo json_encode([
  "resourceType"=>"CapabilityStatement",
  "status"=>"active",
  "date"=>date('c'),
  "fhirVersion"=>"4.0.1",
  "format"=>["json"],
  "rest"=>[["mode"=>"server","resource"=>[["type"=>"Questionnaire"],["type"=>"QuestionnaireResponse"]]]]
]);
?>