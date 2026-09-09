<?php

declare(strict_types=1);

require_once __DIR__ . '/../include/auth.php';
require_once __DIR__ . '/../include/csrf.php';
require_once __DIR__ . '/../include/login_security.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('この操作は許可されていません。');
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('不正なリクエストです。管理画面を開き直してください。');
}

function passwordChangeResult(string $message, bool $success = false): never
{
    // パスワード自体はセッションやURLへ保存しない。
    $_SESSION['password_change_result'] = ['message' => $message, 'success' => $success];
    header('Location: index.php#password-settings', true, 303);
    exit;
}

$currentPassword = postString('current_password');
$newPassword = postString('new_password');
$confirmation = postString('confirm_password');

if ($newPassword !== $confirmation) {
    passwordChangeResult('新しいパスワードが一致しません。もう一度入力してください。');
}

// PASSWORD_DEFAULTのbcryptが72バイト以降を切り捨てることを防ぐ。
if (strlen($newPassword) > 72 || str_contains($newPassword, "\0")
    || !mb_check_encoding($newPassword, 'UTF-8') || mb_strlen($newPassword, 'UTF-8') < 12) {
    passwordChangeResult('新しいパスワードは12文字以上、72バイト以内で入力してください。');
}

if ($currentPassword === '' || strlen($currentPassword) > 4096 || str_contains($currentPassword, "\0")) {
    passwordChangeResult('現在のパスワードを正しく入力してください。');
}

try {
    $pdo = db();
    $attemptKey = 'password-change:' . $_SESSION['admin_id'];
    $retryAfter = reserveLoginAttempt($pdo, $attemptKey, $_SERVER['REMOTE_ADDR'] ?? '');
    if ($retryAfter > 0) {
        passwordChangeResult('試行回数が多すぎます。約' . (int) ceil($retryAfter / 60) . '分後に再度お試しください。');
    }

    $read = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ?');
    $read->execute([$_SESSION['admin_id']]);
    $oldHash = $read->fetchColumn();
    if (!is_string($oldHash) || !password_verify($currentPassword, $oldHash)) {
        passwordChangeResult('現在のパスワードが正しくありません。');
    }
    if (password_verify($newPassword, $oldHash)) {
        passwordChangeResult('現在とは異なる新しいパスワードを入力してください。');
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    // 同時に別の端末で変更された場合は上書きしない。
    $update = $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ? AND password_hash = ?');
    $update->execute([$newHash, $_SESSION['admin_id'], $oldHash]);
    if ($update->rowCount() !== 1) {
        passwordChangeResult('パスワードが別の操作で変更されました。ログインし直してください。');
    }
} catch (Throwable $error) {
    error_log('Admin password change failed.');
    passwordChangeResult('更新できませんでした。時間をおいてもう一度お試しください。');
}

// この端末だけ新しい資格情報で継続し、他のセッションは認証時に失効させる。
if (!session_regenerate_id(true)) {
    $_SESSION = [];
    header('Location: ../pages/login.php', true, 303);
    exit;
}
$_SESSION['admin_password_version'] = hash('sha256', $newHash);
$_SESSION['admin_authenticated_at'] = time();
$_SESSION['admin_last_activity'] = time();
unset($_SESSION['csrf_token']);

passwordChangeResult('パスワードを変更しました。他の端末では再ログインが必要です。', true);
