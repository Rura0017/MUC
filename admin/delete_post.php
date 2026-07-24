<?php

declare(strict_types=1);

require_once __DIR__ . '/../include/auth.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/csrf.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('この操作は許可されていません。');
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('不正なリクエストです。');
}

$postId = filter_input(
    INPUT_POST,
    'post_id',
    FILTER_VALIDATE_INT
);

if ($postId === false || $postId === null) {
    http_response_code(400);
    exit('投稿IDが正しくありません。');
}

$pdo = db();

$stmt = $pdo->prepare(
    '
    DELETE FROM posts
    WHERE id = :id
    '
);

$stmt->execute([
    ':id' => $postId,
]);

header('Location: index.php?deleted=1');
exit;