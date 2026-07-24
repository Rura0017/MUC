<?php

declare(strict_types=1);

require_once __DIR__ . '/include/auth.php';
require_once __DIR__ . '/include/csrf.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('この操作は許可されていません。');
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('不正なリクエストです。');
}

// セッション内の情報を空にする
$_SESSION = [];

// ブラウザ側のセッションCookieも削除する
if (ini_get('session.use_cookies')) {
    $cookieParameters = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $cookieParameters['path'],
        $cookieParameters['domain'],
        $cookieParameters['secure'],
        $cookieParameters['httponly']
    );
}

// サーバー側のセッションを削除する
session_destroy();

header('Location: pages/login.php');
exit;