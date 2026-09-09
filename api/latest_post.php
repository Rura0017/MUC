<?php

declare(strict_types=1);

header(
    'Content-Type: application/json; charset=UTF-8'
);

header(
    'Cache-Control: no-store, no-cache, must-revalidate'
);

require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/post_renderer.php';
require_once __DIR__ . '/../include/post_attachments.php';

/**
 * SQLiteの日時を日本時間の表示用文字列へ変換する。
 */
function formatPostDate(string $createdAt, string $format = 'Y/m/d H:i'): string
{
    try {
        $date = new DateTime(
            $createdAt,
            new DateTimeZone('UTC')
        );

        $date->setTimezone(
            new DateTimeZone('Asia/Tokyo')
        );

        return $date->format($format);
    } catch (Exception) {
        return $createdAt;
    }
}

try {
    $pdo = db();

    $posts = $pdo
        ->query(
            '
            SELECT
                id,
                title,
                body,
                body_format,
                created_at
            FROM posts
            WHERE post_type = \'post\'
            ORDER BY id DESC
            LIMIT 3
            '
        )
        ->fetchAll();

    if ($posts === []) {
        echo json_encode(
            [
                'post' => null,
                'previews' => [],
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );

        exit;
    }

    $post = $posts[0];

    $previews = [];

    foreach ($posts as $previewPost) {
        // 本文から文字だけを取り出し、改行や連続した空白を整える。
        $excerpt = trim(preg_replace(
            '/\s+/u',
            ' ',
            postBodyPlainText($previewPost['body'], $previewPost['body_format'])
        ) ?? '');

        $previews[] = [
            'id' => (int) $previewPost['id'],
            'title' => $previewPost['title'],
            'excerpt' => mb_substr($excerpt, 0, 240, 'UTF-8'),
            'created_at' => formatPostDate(
                $previewPost['created_at']
            ),
            'created_at_iso' => formatPostDate(
                $previewPost['created_at'], DATE_ATOM
            ),
        ];
    }

    // ホームの紹介欄では、本文全体や添付画像を読み込まない。
    if (($_GET['previews'] ?? '') === '1') {
        echo json_encode(
            ['previews' => $previews],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );
        exit;
    }

    $attachments = postAttachmentsForPost(
        $pdo,
        (int) $post['id']
    );

    echo json_encode(
        [
            'previews' => $previews,
            'post' => [
                'id' => (int) $post['id'],
                'title' => $post['title'],

                // renderPostBody()は、文章を安全なHTMLへ変換し、
                // 本文中のX投稿URLを埋め込み用領域へ置き換える。
                'body_html' => renderPostBody(
                    $post['body'],
                    $post['body_format']
                ),

                'attachments_html' => renderPostAttachments(
                    $attachments,
                    './'
                ),

                'created_at' => formatPostDate(
                    $post['created_at']
                ),

                'contains_x_post' =>
                    containsXPostUrl(
                        $post['body'],
                        $post['body_format']
                    ),
            ],
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR
    );
} catch (Throwable $error) {
    http_response_code(500);

    echo json_encode(
        [
            'error' =>
                '最新投稿を取得できませんでした。',
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );
}
