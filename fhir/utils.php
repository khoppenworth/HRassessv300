<?php
require_once __DIR__.'/../config.php';
header('Content-Type: application/json');
function bundle(array $entries): array {
  return ["resourceType"=>"Bundle","type"=>"collection","entry"=>$entries];
}
?>