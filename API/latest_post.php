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

/**
 * SQLiteの日時を日本時間の表示用文字列へ変換する。
 */
function formatPostDate(string $createdAt): string
{
    try {
        $date = new DateTime(
            $createdAt,
            new DateTimeZone('UTC')
        );

        $date->setTimezone(
            new DateTimeZone('Asia/Tokyo')
        );

        return $date->format('Y/m/d H:i');
    } catch (Exception) {
        return $createdAt;
    }
}

try {
    $post = db()
        ->query(
            '
            SELECT
                id,
                title,
                body,
                created_at
            FROM posts
            ORDER BY id DESC
            LIMIT 1
            '
        )
        ->fetch();

    if ($post === false) {
        echo json_encode(
            [
                'post' => null,
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );

        exit;
    }

    echo json_encode(
        [
            'post' => [
                'id' => (int) $post['id'],
                'title' => $post['title'],

                // renderPostBody()は、文章を安全なHTMLへ変換し、
                // 本文中のX投稿URLを埋め込み用領域へ置き換える。
                'body_html' => renderPostBody(
                    $post['body']
                ),

                'created_at' => formatPostDate(
                    $post['created_at']
                ),

                'contains_x_post' =>
                    containsXPostUrl(
                        $post['body']
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
