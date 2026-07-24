<?php

declare(strict_types=1);

/**
 * PHPのセッションを開始する。
 *
 * セッションは、ログイン状態をサーバー側で保持する仕組み。
 */
function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        // JavaScriptからセッションCookieを読み取れなくする
        'httponly' => true,

        // 外部サイト経由でCookieが送られにくくする
        'samesite' => 'Lax',

        // HTTPS通信時だけCookieを送る
        'secure' => isset($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off',
    ]);

    session_start();
}

/**
 * 管理者としてログインしていなければ、
 * ログインページへ戻す。
 */
function requireAdmin(): void
{
    startSession();

    if (!isset($_SESSION['admin_id'])) {
        header('Location: ../pages/login.php');
        exit;
    }
}