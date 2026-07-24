<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * CSRFトークンを取得する。
 *
 * CSRFトークンは、正規の管理画面から送信された操作かを
 * 確認するためのランダムな文字列。
 */
function csrfToken(): string
{
    startSession();

    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * 送信されたCSRFトークンが正しいか確認する。
 */
function verifyCsrfToken(?string $token): bool
{
    startSession();

    if (
        !isset($_SESSION['csrf_token'])
        || !is_string($token)
    ) {
        return false;
    }

    return hash_equals(
        $_SESSION['csrf_token'],
        $token
    );
}