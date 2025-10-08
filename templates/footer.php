<?php
$locale = ensure_locale();
$t = load_lang($locale);
?>
</main>
<footer class="md-footer">
  <div class="md-small text-center"><?=htmlspecialchars(t($t, 'footer_note', 'My Performance — Material-inspired UI. Admin can update logo, site name, landing text, address, and contact.'), ENT_QUOTES, 'UTF-8')?></div>
</footer>
<script>window.APP_BASE_URL = <?=json_encode(BASE_URL, JSON_THROW_ON_ERROR)?>;</script>
<script src="<?=asset_url('assets/js/app.js')?>"></script>