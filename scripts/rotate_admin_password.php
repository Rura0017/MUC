<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// 既存DBだけを更新する緊急用CLI。マイグレーションやアカウント作成は行わない。
// --generate の出力には新パスワードが含まれる。公開ログへ転送しないこと。
$options = getopt('', ['database:', 'username:', 'generate', 'stdin']);
$path = $options['database'] ?? '';
$generate = array_key_exists('generate', $options);
$fromStdin = array_key_exists('stdin', $options);

if (!is_string($path) || $path === '' || $generate === $fromStdin) {
    fwrite(STDERR, "Usage: php scripts/rotate_admin_password.php --database=/private/muc.sqlite [--username=NAME] --generate|--stdin\n");
    exit(1);
}

$path = realpath($path);
if ($path === false || !is_file($path)) {
    fwrite(STDERR, "Existing database not found. No database was created.\n");
    exit(1);
}

$password = $generate
    ? rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=')
    : rtrim((string) stream_get_contents(STDIN, 4097), "\r\n");

if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
    fwrite(STDERR, "Password must contain 12 to 72 bytes, without NUL.\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $username = $options['username'] ?? null;
    if ($username !== null && !is_string($username)) {
        throw new RuntimeException('Invalid username option.');
    }

    $query = $pdo->prepare($username === null
        ? 'SELECT id, username, password_hash FROM admins LIMIT 2'
        : 'SELECT id, username, password_hash FROM admins WHERE username = ?');
    $query->execute($username === null ? [] : [$username]);
    $admins = $query->fetchAll(PDO::FETCH_ASSOC);
    if (count($admins) !== 1) {
        throw new RuntimeException('Specify exactly one existing administrator with --username.');
    }

    $admin = $admins[0];
    if (password_verify($password, $admin['password_hash'])) {
        throw new RuntimeException('Choose a password different from the current password.');
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    $update = $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ? AND password_hash = ?');
    $update->execute([$hash, $admin['id'], $admin['password_hash']]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('The account changed concurrently. No password was changed.');
    }
    $pdo->commit();
    echo json_encode([
        'updated' => true,
        'username' => $admin['username'],
        ...($generate ? ['generated_password' => $password] : []),
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Password rotation failed. Verify the database path, administrator and permissions.\n");
    exit(1);
}
