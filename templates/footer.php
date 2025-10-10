<?php
require_once __DIR__ . '/../config.php';

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$siteTitle = htmlspecialchars($cfg['site_name'] ?? t($t, 'footer_default_site', 'Ethiopian Public Service Sector'), ENT_QUOTES, 'UTF-8');
$orgName = htmlspecialchars(t($t, 'footer_org', 'Ethiopian Public Service Sector'), ENT_QUOTES, 'UTF-8');
$rights = htmlspecialchars(t($t, 'footer_rights', 'All rights reserved.'), ENT_QUOTES, 'UTF-8');
$currentYear = date('Y');
?>
</main>
</div>
<footer class="md-footer">
  <div class="md-footer-inner">
    <div class="md-footer-brand"><?=$siteTitle?></div>
    <div class="md-footer-links">
      <a href="https://epss.gov.et" target="_blank" rel="noopener">epss.gov.et</a>
      <span>•</span>
      <a href="mailto:info@epss.gov.et">info@epss.gov.et</a>
    </div>
    <div class="md-footer-meta">&copy; <?=$currentYear?> <?=$orgName?>. <?=$rights?></div>
  </div>
</footer>
<script>window.APP_BASE_URL = <?=json_encode(BASE_URL, JSON_THROW_ON_ERROR)?>;</script>
<script src="<?=asset_url('assets/js/app.js')?>"></script>
