<?php

declare(strict_types=1);

require_once __DIR__ . '/../include/auth.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/csrf.php';
require_once __DIR__ . '/../include/post_attachments.php';

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
$attachments = postAttachmentsForPost($pdo, $postId);

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        '
        DELETE FROM posts
        WHERE id = :id
        '
    );

    $stmt->execute([
        ':id' => $postId,
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    exit('投稿を削除できませんでした。');
}

deletePostAttachmentFiles($attachments);

header('Location: index.php?deleted=1');
exit;
