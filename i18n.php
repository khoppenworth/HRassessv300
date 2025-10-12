<?php

const SUPPORTED_LOCALES = ['en', 'fr', 'am'];

function sanitize_locale_selection(array $locales): array {
    $requested = [];
    foreach ($locales as $locale) {
        $locale = strtolower(trim((string)$locale));
        if ($locale !== '' && !isset($requested[$locale])) {
            $requested[$locale] = true;
        }
    }

    $result = [];
    foreach (SUPPORTED_LOCALES as $supported) {
        if (isset($requested[$supported])) {
            $result[] = $supported;
        }
    }

    return $result;
}

function enforce_locale_requirements(array $locales): array
{
    $sanitized = sanitize_locale_selection($locales);
    if (!$sanitized) {
        $sanitized = sanitize_locale_selection(SUPPORTED_LOCALES);
    }

    if (!array_intersect($sanitized, ['en', 'fr'])) {
        $sanitized[] = 'en';
        $sanitized = sanitize_locale_selection($sanitized);
    }

    return $sanitized;
}

function remember_available_locales(array $locales): void
{
    $normalized = enforce_locale_requirements($locales);
    $_SESSION['enabled_locales'] = $normalized;

    if (!empty($_SESSION['lang']) && !in_array($_SESSION['lang'], $normalized, true)) {
        $_SESSION['lang'] = $normalized[0];
    }
    if (!empty($_SESSION['user']['language']) && !in_array($_SESSION['user']['language'], $normalized, true)) {
        $_SESSION['user']['language'] = $normalized[0];
    }
}

function locale_display_name(string $locale): string
{
    $names = [
        'en' => 'English',
        'fr' => 'French',
        'am' => 'Amharic',
    ];

    $locale = strtolower($locale);
    return $names[$locale] ?? strtoupper($locale);
}

function available_locales(): array {
    $sessionValue = $_SESSION['enabled_locales'] ?? null;

    if (is_string($sessionValue) && $sessionValue !== '') {
        $decoded = json_decode($sessionValue, true);
        $sessionValue = is_array($decoded) ? $decoded : null;
    }

    if (is_array($sessionValue)) {
        $locales = enforce_locale_requirements($sessionValue);
        $_SESSION['enabled_locales'] = $locales;
        return $locales;
    }

    $defaults = enforce_locale_requirements(SUPPORTED_LOCALES);
    $_SESSION['enabled_locales'] = $defaults;
    return $defaults;
}

function resolve_locale(?string $locale): string {
    $locale = strtolower((string)$locale);
    $enabled = available_locales();
    return in_array($locale, $enabled, true) ? $locale : ($enabled[0] ?? 'en');
}

function locale_cookie_path(): string {
    $cookiePath = '/';
    if (defined('BASE_URL')) {
        $parsedPath = parse_url(BASE_URL, PHP_URL_PATH);
        if (is_string($parsedPath) && $parsedPath !== '') {
            $cookiePath = rtrim($parsedPath, '/') ?: '/';
        }
    }
    return $cookiePath;
}

function ensure_locale(): string {
    $candidate = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? null);
    $locale = resolve_locale($candidate);
    $_SESSION['lang'] = $locale;

    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        setcookie('lang', $locale, [
            'expires' => time() + (365 * 24 * 60 * 60),
            'path' => locale_cookie_path(),
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    return $locale;
}

function load_lang(string $lang): array {
    $lang = resolve_locale($lang);
    $file = __DIR__ . "/lang/$lang.json";
    if (!file_exists($file)) {
        $file = __DIR__ . '/lang/en.json';
    }
    $json = is_readable($file) ? file_get_contents($file) : '{}';
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
}

function t(array $translations, string $key, string $fallback = ''): string {
    $value = $translations[$key] ?? ($fallback !== '' ? $fallback : $key);
    return is_string($value) ? $value : $fallback;
}

?>