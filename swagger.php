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
    #swagger-ui {
      background: var(--app-surface);
      color: inherit;
      border-radius: 0 0 12px 12px;
      overflow: hidden;
    }
    .swagger-ui .topbar {
      background: linear-gradient(92deg, var(--app-primary-dark), var(--app-primary));
      border-bottom: 1px solid var(--app-border);
    }
    .swagger-ui .topbar a span {
      color: #fff !important;
      font-weight: 600;
      letter-spacing: 0.01em;
    }
    .swagger-ui .topbar .download-url-wrapper input {
      border-radius: 999px;
      border-color: var(--app-border);
      color: inherit;
    }
    .swagger-ui .btn,
    .swagger-ui .btn.authorize {
      background: var(--app-primary) !important;
      border-color: var(--app-primary-dark) !important;
      color: #fff !important;
      box-shadow: 0 6px 16px rgba(17, 68, 117, 0.24);
    }
    .swagger-ui .btn.authorize svg {
      fill: currentColor;
    }
    .swagger-ui .model-box-control,
    .swagger-ui .opblock .opblock-summary-method {
      background: var(--app-primary-dark) !important;
      color: #fff !important;
    }
    .swagger-ui .scheme-container {
      border-bottom: 1px solid var(--app-border);
      box-shadow: none;
      background: transparent;
    }
    .swagger-ui .info .title,
    .swagger-ui .opblock-tag.no-desc {
      color: var(--app-muted);
    }
    .swagger-ui .opblock.opblock-get {
      border-color: rgba(32, 115, 191, 0.32);
      background: rgba(32, 115, 191, 0.06);
    }
    .swagger-ui .opblock.opblock-post {
      border-color: rgba(97, 179, 236, 0.32);
      background: rgba(97, 179, 236, 0.08);
    }
    .md-step-list {
      margin: 0;
      padding-left: 1.25rem;
      color: var(--app-muted);
    }
    .md-step-list li {
      margin-bottom: 0.4rem;
    }
    .md-step-list strong {
      color: var(--app-primary);
      font-weight: 600;
    }
    .swagger-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      margin-top: 1rem;
    }
  </style>
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'api_documentation','API Documentation')?></h2>
    <p><?=t($t,'api_documentation_intro','Explore the available REST and FHIR endpoints exposed by the platform. Authentication is required to call protected endpoints.')?></p>
    <ol class="md-step-list">
      <li><?=t($t, 'api_doc_step_sign_in', 'Sign in as an administrator to establish your session before making API calls.')?></li>
      <li><?=t($t, 'api_doc_step_open', 'Open this page to load the live OpenAPI specification and review endpoint details.')?></li>
      <li><?=t($t, 'api_doc_step_authorize', 'Use the <strong>Authorize</strong> button to include your session cookie or copy the sample curl commands to test from a terminal.')?></li>
      <li><?=t($t, 'api_doc_step_try_it', 'Select an operation, expand it, and click <strong>Try it out</strong> to execute a request with the current theme styling applied to all controls.')?></li>
    </ol>
    <div class="swagger-actions">
      <a class="md-button md-outline" href="<?=htmlspecialchars($openapiUrl, ENT_QUOTES, 'UTF-8')?>" target="_blank" rel="noopener">&rarr; <?=t($t,'download_openapi_spec','Download OpenAPI JSON')?></a>
      <a class="md-button" href="https://swagger.io/tools/swagger-ui/" target="_blank" rel="noopener">Swagger UI docs</a>
    </div>
    <p><?=t($t,'api_doc_reference_hint','Need a guided walkthrough? The description pane in the viewer now highlights authentication, response formats, and draft workflows.')?></p>
  </div>
  <div class="md-card md-elev-2" style="padding:0;">
    <div id="swagger-ui"></div>
  </div>
</section>
<?php include __DIR__.'/templates/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js" integrity="sha384-VnuG1v7rmDdGztJ32thSWfW5i8ubrSMVqGpfR+L5/TrF4iAfDdc0AGJi/7luWUv" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-standalone-preset.js" integrity="sha384-lhDX2PD6o642kvJy3ocHxVhdkIJfnddFktKI1IvY2ag6GLZ35Xwqr7zaCDW8Vh6u" crossorigin="anonymous"></script>
<script>
  window.addEventListener('DOMContentLoaded', function () {
    window.ui = SwaggerUIBundle({
      url: '<?=htmlspecialchars($openapiUrl, ENT_QUOTES, 'UTF-8')?>',
      dom_id: '#swagger-ui',
      presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
      layout: 'StandaloneLayout',
      deepLinking: true,
      docExpansion: 'list',
      defaultModelRendering: 'model',
      defaultModelsExpandDepth: 1
    });
  });
</script>
</body>
</html>
