<?php

declare(strict_types=1);

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function verify(?string $token): void
    {
        $session = $_SESSION['_csrf'] ?? '';
        if ($token === null || $session === '' || !hash_equals($session, $token)) {
            http_response_code(419);
            exit('درخواست نامعتبر (CSRF). صفحه را تازه کنید و دوباره تلاش کنید.');
        }
    }
}
