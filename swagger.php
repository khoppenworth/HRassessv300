<?php
require_once __DIR__ . '/config.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$openapiUrl = asset_url('docs/openapi.json');
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t,'api_documentation','API Documentation'), ENT_QUOTES, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
  <link rel="manifest" href="<?=asset_url('manifest.webmanifest')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css" integrity="sha384-x3uAv89xDSHLVQQDBlnJrped1IovnHgwlHGawEq+y3OC/YLXTr4Wr9PXgC7cmkQC" crossorigin="anonymous">
  <style>
    #swagger-ui { background: #fff; }
  </style>
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'api_documentation','API Documentation')?></h2>
    <p><?=t($t,'api_documentation_intro','Explore the available REST and FHIR endpoints exposed by the platform. Authentication is required to call protected endpoints.')?></p>
  </div>
  <div class="md-card md-elev-2" style="padding:0;">
    <div id="swagger-ui"></div>
  </div>
</section>
<?php include __DIR__.'/templates/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js" integrity="sha384-VnuG1v7rmDdGztJ32thSWfW5i8uVbrSMVqGpfR+L5/TrF4iAfDdc0AGJi/7luWUv" crossorigin="anonymous"></script>
<script>
  window.addEventListener('DOMContentLoaded', function () {
    window.ui = SwaggerUIBundle({
      url: '<?=htmlspecialchars($openapiUrl, ENT_QUOTES, 'UTF-8')?>',
      dom_id: '#swagger-ui',
      presets: [SwaggerUIBundle.presets.apis],
      layout: 'BaseLayout'
    });
  });
</script>
</body>
</html>
