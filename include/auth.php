<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

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

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        // JavaScriptからセッションCookieを読み取れなくする
        'httponly' => true,

        // 外部サイト経由でCookieが送られにくくする
        'samesite' => 'Lax',

        // HTTPS通信時だけCookieを送る
        // HTTPS終端がリバースプロキシでもSecureを外さない。
        // HTTPのローカル開発時だけ明示的に0を指定する。
        'secure' => getenv('MUC_SESSION_SECURE') !== '0',
    ]);

    if (!session_start()) {
        throw new RuntimeException('Session storage is unavailable.');
    }
    header('Cache-Control: no-store');
}

function isAdminSessionValid(): bool
{
    startSession();
    if (!isset($_SESSION['admin_id'])) {
        return false;
    }

    $now = time();
    $valid = is_int($_SESSION['admin_id'])
        && $now - ($_SESSION['admin_authenticated_at'] ?? 0) < 28800
        && $now - ($_SESSION['admin_last_activity'] ?? 0) < 1800;

    if ($valid) {
        require_once __DIR__ . '/db.php';
        $statement = db()->prepare('SELECT password_hash FROM admins WHERE id = ?');
        $statement->execute([$_SESSION['admin_id']]);
        $passwordHash = $statement->fetchColumn();
        $valid = is_string($passwordHash)
            && hash_equals(hash('sha256', $passwordHash), $_SESSION['admin_password_version'] ?? '');
    }

    if (!$valid) {
        $_SESSION = [];
        session_regenerate_id(true);
        return false;
    }

    $_SESSION['admin_last_activity'] = $now;
    return true;
}

/**
 * 管理者としてログインしていなければ、
 * ログインページへ戻す。
 */
function requireAdmin(): void
{
    startSession();

    if (!isAdminSessionValid()) {
        header('Location: ../pages/login.php');
        exit;
    }
}
