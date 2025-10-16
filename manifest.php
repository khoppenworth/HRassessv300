<?php
require_once __DIR__ . '/config.php';

$buildManifest = static function (array $cfg): array {
    $tokens = site_theme_tokens($cfg);
    $theme = site_color_theme($cfg);
    $themeVars = $tokens[$theme === 'dark' ? 'dark' : 'light'] ?? [];
    $rootVars = $tokens['root'] ?? [];
    $brand = site_brand_palette($cfg);

    $themeColorCandidate = $rootVars['--brand-primary'] ?? $brand['primary'];
    $themeColor = normalize_hex_color($themeColorCandidate) ?? $brand['primary'];

    $backgroundCandidates = [
        $themeVars['--app-surface'] ?? null,
        $themeVars['--app-background'] ?? null,
        $rootVars['--brand-bg'] ?? null,
    ];

    $backgroundColor = null;
    foreach ($backgroundCandidates as $candidate) {
        if ($candidate !== null && trim((string)$candidate) !== '') {
            $backgroundColor = $candidate;
            break;
        }
    }

    $isGradient = static function (?string $value): bool {
        if ($value === null) {
            return false;
        }
        $trimmed = strtolower(trim($value));
        return str_starts_with($trimmed, 'linear-gradient') || str_starts_with($trimmed, 'radial-gradient');
    };

    if ($isGradient($backgroundColor)) {
        $backgroundColor = null;
    }

    $backgroundColor = normalize_hex_color((string)$backgroundColor);
    if ($backgroundColor === null) {
        $backgroundColor = $theme === 'dark'
            ? shade_color($brand['primary'], 0.82)
            : tint_color($brand['primary'], 0.92);
    }

    return [
        'name' => (string)($cfg['site_name'] ?? 'My Performance'),
        'short_name' => (string)($cfg['site_name'] ?? 'Performance'),
        'start_url' => 'my_performance.php',
        'display' => 'standalone',
        'background_color' => $backgroundColor,
        'theme_color' => $themeColor,
        'icons' => [
            [
                'src' => asset_url('logo.php'),
                'sizes' => '192x192',
                'type' => 'image/svg+xml',
            ],
        ],
    ];
};

try {
    $cfg = get_site_config($pdo);
    $manifest = $buildManifest($cfg);
} catch (Throwable $e) {
    error_log('manifest generation failed: ' . $e->getMessage());
    $manifest = $buildManifest(site_config_defaults());
}

$json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($json === false) {
    error_log('manifest json_encode failure: ' . json_last_error_msg());
    $fallback = [
        'name' => 'My Performance',
        'short_name' => 'Performance',
        'start_url' => 'my_performance.php',
        'display' => 'standalone',
        'background_color' => '#2073bf',
        'theme_color' => '#2073bf',
        'icons' => [
            [
                'src' => asset_url('logo.php'),
                'sizes' => '192x192',
                'type' => 'image/svg+xml',
            ],
        ],
    ];
    $json = json_encode($fallback, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

header('Content-Type: application/manifest+json; charset=utf-8');
echo $json ?: '{}';
