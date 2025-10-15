<?php
require_once __DIR__ . '/config.php';

auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);

$locale = ensure_locale();
$t = load_lang($locale);
$openapiUrl = asset_url('docs/openapi.json');
$nonce = csp_nonce();
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=htmlspecialchars(t($t, 'api_documentation', 'API Documentation'), ENT_QUOTES, 'UTF-8')?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css" integrity="sha384-x3uAv89xDSHLVQQDBlnJrped1IovnHgwlHGawEq+y3OC/YLXTr4Wr9PXgC7cmkQC" crossorigin="anonymous">
  <style>
    body {
      margin: 0;
      background: #fafafa;
    }
  </style>
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js" integrity="sha384-VnuG1v7rmDdGztJ32thSWfW5i8ubrSMVqGpfR+L5/TrF4iAfDdc0AGJi/7luWUv" crossorigin="anonymous"></script>
  <script nonce="<?=htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8')?>">
    window.addEventListener('load', function () {
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
