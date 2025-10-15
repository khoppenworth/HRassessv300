<?php
require_once __DIR__ . '/config.php';

auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);

$locale = ensure_locale();
$t = load_lang($locale);
$openapiUrl = asset_url('docs/openapi.json');
$nonce = csp_nonce();

$downloadLabel = t($t, 'download_openapi_spec', 'Download OpenAPI JSON');
$loadingText = t($t, 'api_doc_loading', 'Loading API documentation…');
$viewerFailedText = t($t, 'api_doc_viewer_failed', 'Unable to load the interactive viewer. You can still download the OpenAPI JSON below.');
$introText = t($t, 'api_documentation_intro', 'Explore the available REST and FHIR endpoints exposed by the platform. Authentication is required to call protected endpoints.');
$referenceHint = t($t, 'api_doc_reference_hint', 'Need a guided walkthrough? The description pane in the viewer now highlights authentication, response formats, and draft workflows.');

$steps = [
    t($t, 'api_doc_step_sign_in', 'Sign in as an administrator to establish your session before making API calls.'),
    t($t, 'api_doc_step_open', 'Open this page to load the live OpenAPI specification and review endpoint details.'),
    t($t, 'api_doc_step_authorize', 'Use the Authorize button to include your session cookie or copy the sample curl commands to test from a terminal.'),
    t($t, 'api_doc_step_try_it', 'Select an operation, expand it, and click Try it out to execute a request with the current theme styling applied to all controls.'),
];

$downloadUrl = htmlspecialchars($openapiUrl, ENT_QUOTES, 'UTF-8');
$downloadTextHtml = htmlspecialchars($downloadLabel, ENT_QUOTES, 'UTF-8');
$downloadTextJs = json_encode($downloadLabel, JSON_THROW_ON_ERROR);
$loadingLabelHtml = htmlspecialchars($loadingText, ENT_QUOTES, 'UTF-8');
$viewerFailedHtml = htmlspecialchars($viewerFailedText, ENT_QUOTES, 'UTF-8');
$viewerFailedJs = json_encode($viewerFailedText, JSON_THROW_ON_ERROR);
$introContent = htmlspecialchars($introText, ENT_QUOTES, 'UTF-8');
$referenceContent = htmlspecialchars($referenceHint, ENT_QUOTES, 'UTF-8');
$pageTitle = htmlspecialchars(t($t, 'api_documentation', 'API Documentation'), ENT_QUOTES, 'UTF-8');
$localeEsc = htmlspecialchars($locale, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?=$localeEsc?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=$pageTitle?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css" crossorigin="anonymous">
  <style>
    body {
      margin: 0;
      background: #fafafa;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
      color: #212121;
    }
    .swagger-shell {
      display: grid;
      grid-template-columns: minmax(0, 320px) 1fr;
      gap: 24px;
      padding: 24px;
      box-sizing: border-box;
    }
    .swagger-intro {
      background: #fff;
      border-radius: 12px;
      padding: 24px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    }
    .swagger-intro h1 {
      margin-top: 0;
      margin-bottom: 12px;
      font-size: 24px;
    }
    .swagger-intro p {
      margin: 0 0 12px;
      line-height: 1.5;
    }
    .swagger-intro ol {
      padding-left: 20px;
      margin: 0 0 16px;
    }
    .swagger-intro li {
      margin-bottom: 8px;
    }
    .swagger-download-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #0057b8;
      color: #fff;
      padding: 10px 16px;
      border-radius: 999px;
      text-decoration: none;
      font-weight: 600;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }
    .swagger-download-link:hover,
    .swagger-download-link:focus {
      background: #004494;
      color: #fff;
    }
    #swagger-ui {
      min-height: 480px;
    }
    .swagger-fallback,
    .swagger-error {
      background: #fff;
      border-radius: 12px;
      padding: 32px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    }
    .swagger-fallback p,
    .swagger-error p {
      margin: 0 0 16px;
      line-height: 1.5;
    }
    @media (max-width: 960px) {
      .swagger-shell {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <main class="swagger-shell">
    <section class="swagger-intro" aria-labelledby="api-docs-title">
      <h1 id="api-docs-title"><?=$pageTitle?></h1>
      <p><?=$introContent?></p>
      <p><?=$referenceContent?></p>
      <ol>
        <?php foreach ($steps as $step): ?>
          <li><?=$step?></li>
        <?php endforeach; ?>
      </ol>
      <p>
        <a class="swagger-download-link" href="<?=$downloadUrl?>" target="_blank" rel="noopener">
          <?=$downloadTextHtml?>
        </a>
      </p>
    </section>
    <div id="swagger-ui">
      <div class="swagger-fallback" role="status" aria-live="polite">
        <p><?=$loadingLabelHtml?></p>
        <p>
          <a class="swagger-download-link" href="<?=$downloadUrl?>" target="_blank" rel="noopener">
            <?=$downloadTextHtml?>
          </a>
        </p>
      </div>
    </div>
  </main>
  <noscript>
    <div class="swagger-error">
      <p><?=$viewerFailedHtml?></p>
      <p><a class="swagger-download-link" href="<?=$downloadUrl?>" target="_blank" rel="noopener"><?=$downloadTextHtml?></a></p>
    </div>
  </noscript>
  <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js" crossorigin="anonymous"></script>
  <script nonce="<?=htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8')?>">
    window.addEventListener('load', function () {
      var container = document.getElementById('swagger-ui');
      if (!container) {
        return;
      }

      if (typeof window.SwaggerUIBundle !== 'function') {
        container.innerHTML = '';
        var fallback = document.createElement('div');
        fallback.className = 'swagger-error';

        var message = document.createElement('p');
        message.textContent = <?=$viewerFailedJs?>;
        fallback.appendChild(message);

        var linkWrapper = document.createElement('p');
        var link = document.createElement('a');
        link.href = '<?=$downloadUrl?>';
        link.className = 'swagger-download-link';
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = <?=$downloadTextJs?>;
        linkWrapper.appendChild(link);
        fallback.appendChild(linkWrapper);

        container.appendChild(fallback);
        return;
      }

      SwaggerUIBundle({
        url: '<?=htmlspecialchars($openapiUrl, ENT_QUOTES, 'UTF-8')?>',
        dom_id: '#swagger-ui',
        withCredentials: true,
        persistAuthorization: true,
        requestInterceptor: function (request) {
          request.credentials = 'include';
          return request;
        }
      });
    });
  </script>
</body>
</html>
