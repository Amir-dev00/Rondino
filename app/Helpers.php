<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function base_path(string $path = ''): string
{
    $root = dirname(__DIR__);
    return $path === '' ? $root : $root . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
}

function config_path(): string
{
    return base_path('config/local.php');
}

function load_config(): array
{
    $path = config_path();
    if (!is_file($path)) {
        return [];
    }
    $cfg = require $path;
    return is_array($cfg) ? $cfg : [];
}

function config(string $key, mixed $default = null): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = load_config();
    }
    $parts = explode('.', $key);
    $cur = $cfg;
    foreach ($parts as $p) {
        if (!is_array($cur) || !array_key_exists($p, $cur)) {
            return $default;
        }
        $cur = $cur[$p];
    }
    return $cur;
}

function now_dt(): string
{
    return gmdate('Y-m-d H:i:s');
}

function fa_datetime(?string $utc): string
{
    if ($utc === null || $utc === '') {
        return '—';
    }
    $ts = strtotime($utc . ' UTC');
    if ($ts === false) {
        return e($utc);
    }
    return e(date('Y-m-d H:i', $ts + (3 * 3600 + 1800))); // +03:30 display
}

function format_toman(?int $amount): string
{
    if ($amount === null) {
        return '—';
    }
    return number_format($amount) . ' تومان';
}

function condition_label(string $normalized): string
{
    return match ($normalized) {
        'factory_sealed' => 'صفر پک',
        'new_unspecified' => 'صفر',
        'like_new' => 'در حد نو',
        'used' => 'کارکرده',
        default => 'نامشخص',
    };
}

function price_type_label(string $type): string
{
    return match ($type) {
        'fixed' => 'مقطوع',
        'negotiable' => 'توافقی',
        'installment' => 'اقساط / پیش‌پرداخت',
        'call' => 'استعلام',
        default => 'نامشخص',
    };
}

function ltr_phone(string $phone): string
{
    return '<span class="ltr" dir="ltr">' . e($phone) . '</span>';
}

function flash_set(string $type, string $message): void
{
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (!isset($_SESSION['_flash'])) {
        return null;
    }
    $f = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    return $f;
}

function request_is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $key, ?string $default = null): ?string
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

function get_q(string $key, ?string $default = null): ?string
{
    return isset($_GET[$key]) ? trim((string) $_GET[$key]) : $default;
}

function absolute_url(string $base, string $href): string
{
    $href = trim($href);
    if ($href === '') {
        return $base;
    }
    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }
    if (str_starts_with($href, '//')) {
        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $href;
    }
    $parts = parse_url($base);
    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? '';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    if (str_starts_with($href, '/')) {
        return $scheme . '://' . $host . $port . $href;
    }
    $path = $parts['path'] ?? '/';
    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
    return $scheme . '://' . $host . $port . $dir . '/' . $href;
}

function page_query_url(string $pageUrl, string $param, int $page): string
{
    $parts = parse_url($pageUrl);
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    $query[$param] = $page;
    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? '';
    $path = $parts['path'] ?? '/';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    return $scheme . '://' . $host . $port . $path . '?' . http_build_query($query);
}

function app_log(string $message): void
{
    $file = base_path('storage/logs/app.log');
    @file_put_contents($file, '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
}
