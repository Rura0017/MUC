<?php

declare(strict_types=1);

// 詳細はサーバーログへ記録し、パスやSQLをレスポンスへ漏らさない。
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
}

function postString(string $name, string $default = ''): string
{
    $value = $_POST[$name] ?? $default;
    if (!is_string($value)) {
        http_response_code(400);
        exit('入力の形式が正しくありません。');
    }
    return $value;
}
