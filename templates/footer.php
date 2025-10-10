<?php
require_once __DIR__ . '/../config.php';

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$siteTitle = htmlspecialchars($cfg['site_name'] ?? t($t, 'footer_default_site', 'Ethiopian Pharmaceutical Supply Service'), ENT_QUOTES, 'UTF-8');
$orgName = htmlspecialchars(t($t, 'footer_org', 'Ethiopian Pharmaceutical Supply Service'), ENT_QUOTES, 'UTF-8');
$orgShort = htmlspecialchars(t($t, 'footer_org_short', 'EPSS / EPS'), ENT_QUOTES, 'UTF-8');
$rights = htmlspecialchars(t($t, 'footer_rights', 'All rights reserved.'), ENT_QUOTES, 'UTF-8');
$currentYear = date('Y');
?>
</main>
</div>
<footer class="md-footer">
  <div class="md-footer-inner">
    <div class="md-footer-brand">
      <span class="md-footer-brand-short"><?=$orgShort?></span>
      <span class="md-footer-brand-name"><?=$siteTitle?></span>
    </div>
    <div class="md-footer-links">
      <a href="https://epss.gov.et" target="_blank" rel="noopener">epss.gov.et</a>
      <span>•</span>
      <a href="mailto:info@epss.gov.et">info@epss.gov.et</a>
      <span>•</span>
      <a href="tel:+251111559900">+251 11 155 9900</a>
      <span>•</span>
      <a href="tel:939">Hotline 939</a>
    </div>
    <div class="md-footer-meta">&copy; <?=$currentYear?> <?=$orgName?>. <?=$rights?></div>
  </div>
</footer>
<script>window.APP_BASE_URL = <?=json_encode(BASE_URL, JSON_THROW_ON_ERROR)?>;</script>
<script src="<?=asset_url('assets/js/app.js')?>"></script>
