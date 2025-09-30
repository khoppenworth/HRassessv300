<?php
function load_lang(string $lang): array {
    $file = __DIR__ . "/lang/$lang.json";
    if (!file_exists($file)) { $file = __DIR__ . "/lang/en.json"; }
    $json = file_get_contents($file);
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
}
function t(array $t, string $key, string $fallback=''): string {
    return $t[$key] ?? ($fallback ?: $key);
}
?>