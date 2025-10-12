<?php
require_once __DIR__ . '/config.php';

$cfg = get_site_config($pdo);
$brand = site_brand_palette($cfg);
$primary = $brand['primary'];
$gradientEnd = tint_color($primary, 0.42);
$accent = tint_color($primary, 0.68);
$outline = shade_color($primary, 0.4);
$textColor = contrast_color($primary);
$glow = rgba_string($accent, 0.35);

$svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200" role="img" aria-label="Site logo">
  <defs>
    <linearGradient id="brandGradient" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="{$primary}" />
      <stop offset="100%" stop-color="{$gradientEnd}" />
    </linearGradient>
    <filter id="brandShadow" x="-20%" y="-20%" width="140%" height="140%">
      <feDropShadow dx="0" dy="18" stdDeviation="18" flood-color="{$glow}" flood-opacity="1" />
    </filter>
  </defs>
  <rect x="10" y="10" width="180" height="180" rx="36" fill="url(#brandGradient)" stroke="{$outline}" stroke-width="2" filter="url(#brandShadow)" />
  <circle cx="100" cy="100" r="60" fill="{$accent}" opacity="0.12" />
  <text x="100" y="118" text-anchor="middle" font-family="'Montserrat','Poppins','Segoe UI',sans-serif" font-size="56" font-weight="600" fill="{$textColor}">EPSS</text>
</svg>
SVG;

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=600');
echo $svg;
