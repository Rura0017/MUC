<?php

declare(strict_types=1);

// CLIは、PowerShellやターミナルからPHPを実行する方式
// ブラウザ経由で実行された場合は処理を中止する
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../include/db.php';

// コマンドの2番目の値をユーザー名として受け取る
// 例: php scripts/create_admin.php admin
$username = trim($argv[1] ?? '');

if ($username === '') {
    fwrite(
        STDERR,
        "使い方: php scripts/create_admin.php ユーザー名\n"
    );
    exit(1);
}

$password = readline('管理者パスワード: ');

if (strlen($password) < 12) {
    fwrite(
        STDERR,
        "パスワードは12文字以上にしてください。\n"
    );
    exit(1);
}

// password_hash()は、パスワードを復元困難な値に変換する関数
$passwordHash = password_hash(
    $password,
    PASSWORD_DEFAULT
);

$pdo = db();

// prepare()は、SQLと入力値を分けて安全に処理する機能
$stmt = $pdo->prepare(
    '
    INSERT INTO admins (
        username,
        password_hash
    )
    VALUES (
        :username,
        :password_hash
    )
    ON CONFLICT(username)
    DO UPDATE SET
        password_hash = excluded.password_hash
    '
);

$stmt->execute([
    ':username' => $username,
    ':password_hash' => $passwordHash,
]);

echo "管理者 {$username} を作成・更新しました。\n";