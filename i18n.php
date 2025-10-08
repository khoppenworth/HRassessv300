<?php

const AVAILABLE_LOCALES = ['en', 'fr', 'am'];

function available_locales(): array {
    return AVAILABLE_LOCALES;
}

function resolve_locale(?string $locale): string {
    $locale = strtolower((string)$locale);
    return in_array($locale, AVAILABLE_LOCALES, true) ? $locale : 'en';
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