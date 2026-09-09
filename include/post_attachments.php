<?php

declare(strict_types=1);

const POST_ATTACHMENT_MAX_FILES = 8;
const POST_ATTACHMENT_MAX_FILE_SIZE = 26214400;
const POST_ATTACHMENT_MAX_TOTAL_SIZE = 104857600;

/**
 * 投稿に添付できる実ファイルの MIME タイプと拡張子を定義する。
 * SVG はスクリプトを含められるため、画像としては受け付けない。
 *
 * @return array<string, array{extension: string, kind: string}>
 */
function postAttachmentTypes(): array
{
    return [
        'image/jpeg' => [
            'extension' => 'jpg',
            'kind' => 'image',
        ],
        'image/png' => [
            'extension' => 'png',
            'kind' => 'image',
        ],
        'image/gif' => [
            'extension' => 'gif',
            'kind' => 'image',
        ],
        'image/webp' => [
            'extension' => 'webp',
            'kind' => 'image',
        ],
        'video/mp4' => [
            'extension' => 'mp4',
            'kind' => 'video',
        ],
        'video/webm' => [
            'extension' => 'webm',
            'kind' => 'video',
        ],
        'video/quicktime' => [
            'extension' => 'mov',
            'kind' => 'video',
        ],
    ];
}

function postAttachmentDirectory(): string
{
    return dirname(__DIR__) . '/image/uploads';
}

function postAttachmentUploadErrorMessage(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE =>
            '添付ファイルは1件25MB以下にしてください。',
        UPLOAD_ERR_PARTIAL =>
            '添付ファイルを最後まで受信できませんでした。もう一度お試しください。',
        UPLOAD_ERR_NO_TMP_DIR =>
            '添付ファイル用の一時フォルダが見つかりません。',
        UPLOAD_ERR_CANT_WRITE =>
            '添付ファイルをサーバーへ保存できませんでした。',
        UPLOAD_ERR_EXTENSION =>
            '添付ファイルがサーバー設定によって拒否されました。',
        default =>
            '添付ファイルのアップロードに失敗しました。',
    };
}

function detectPostAttachmentMimeType(string $path): ?string
{
    $image = @getimagesize($path);
    if (is_array($image) && isset(postAttachmentTypes()[$image['mime'] ?? ''])) {
        // 極端に大きな画像や壊れた画像ヘッダーを受け付けない。
        if ($image[0] < 1 || $image[1] < 1 || $image[0] > 12000 || $image[1] > 12000
            || $image[0] * $image[1] > 40000000) {
            return null;
        }
        return $image['mime'];
    }

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($path);

        if (is_string($detected) && (postAttachmentTypes()[$detected]['kind'] ?? null) === 'video') {
            return $detected;
        }
    }

    $handle = fopen($path, 'rb');

    if ($handle === false) {
        return null;
    }

    $header = fread($handle, 32);
    fclose($handle);

    if (!is_string($header)) {
        return null;
    }

    if (str_starts_with($header, "\x1a\x45\xdf\xa3")) {
        return 'video/webm';
    }

    if (substr($header, 4, 4) === 'ftyp') {
        return substr($header, 8, 4) === 'qt  '
            ? 'video/quicktime'
            : 'video/mp4';
    }

    return null;
}

/**
 * フォームから受け取った添付ファイルを検証する。保存はまだ行わない。
 *
 * @param array<string, mixed>|null $files
 * @param array<int, string>|null $allowedKinds
 * @return array<int, array{
 *     upload_key: string,
 *     tmp_name: string,
 *     original_name: string,
 *     mime_type: string,
 *     media_kind: string,
 *     extension: string,
 *     size: int
 * }>
 */
function collectPostAttachments(
    ?array $files,
    ?array $allowedKinds = null
): array
{
    if ($files === null || !isset($files['error'])) {
        return [];
    }

    $errors = $files['error'];

    if (!is_array($errors)) {
        $errors = [$errors];

        foreach (['name', 'tmp_name', 'size'] as $key) {
            $files[$key] = [$files[$key] ?? ''];
        }
    }

    $uploads = [];
    $totalSize = 0;

    foreach ($errors as $index => $errorCode) {
        if (!is_int($errorCode)
            || !is_string($files['tmp_name'][$index] ?? null)
            || !is_string($files['name'][$index] ?? null)
            || !is_int($files['size'][$index] ?? null)) {
            throw new RuntimeException('添付ファイルの形式が正しくありません。');
        }
        $errorCode = (int) $errorCode;

        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new RuntimeException(
                postAttachmentUploadErrorMessage($errorCode)
            );
        }

        if (count($uploads) >= POST_ATTACHMENT_MAX_FILES) {
            throw new RuntimeException(
                '添付できるファイルは1投稿につき8件までです。'
            );
        }

        $tmpName = (string) ($files['tmp_name'][$index] ?? '');

        if (!is_uploaded_file($tmpName)) {
            throw new RuntimeException(
                '添付ファイルを確認できませんでした。'
            );
        }

        $size = filesize($tmpName);

        if ($size === false || $size < 1 || $size > POST_ATTACHMENT_MAX_FILE_SIZE) {
            throw new RuntimeException(
                '添付ファイルは1件25MB以下にしてください。'
            );
        }

        $totalSize += $size;

        if ($totalSize > POST_ATTACHMENT_MAX_TOTAL_SIZE) {
            throw new RuntimeException(
                '添付ファイルの合計サイズは100MB以下にしてください。'
            );
        }

        $mimeType = detectPostAttachmentMimeType($tmpName);
        $types = postAttachmentTypes();

        if ($mimeType === null || !isset($types[$mimeType])) {
            throw new RuntimeException(
                'JPEG・PNG・GIF・WebP・MP4・WebM・MOV形式のファイルを選択してください。'
            );
        }

        $mediaKind = $types[$mimeType]['kind'];

        if (
            $allowedKinds !== null
            && !in_array($mediaKind, $allowedKinds, true)
        ) {
            throw new RuntimeException(
                '本文の途中には画像ファイルだけを挿入できます。'
            );
        }

        $originalName = trim((string) ($files['name'][$index] ?? ''));
        $originalName = str_replace(["\0", '/', '\\'], ' ', $originalName);

        if ($originalName === '') {
            $originalName = '添付ファイル';
        }

        $uploads[] = [
            'upload_key' => (string) $index,
            'tmp_name' => $tmpName,
            'original_name' => mb_substr(
                $originalName,
                0,
                255,
                'UTF-8'
            ),
            'mime_type' => $mimeType,
            'media_kind' => $mediaKind,
            'extension' => $types[$mimeType]['extension'],
            'size' => $size,
        ];
    }

    return $uploads;
}

/**
 * 本文内画像と末尾添付を合わせた上限を確認する。
 *
 * @param array<int, array<string, mixed>> $uploads
 */
function validatePostAttachmentTotals(array $uploads): void
{
    if (count($uploads) > POST_ATTACHMENT_MAX_FILES) {
        throw new RuntimeException(
            '本文内画像は8件までです。'
        );
    }

    $totalSize = array_sum(
        array_map(
            static fn (array $upload): int =>
                (int) ($upload['size'] ?? 0),
            $uploads
        )
    );

    if ($totalSize > POST_ATTACHMENT_MAX_TOTAL_SIZE) {
        throw new RuntimeException(
            '本文内画像の合計は100MB以下にしてください。'
        );
    }
}

function ensurePostAttachmentDirectory(): void
{
    $directory = postAttachmentDirectory();

    if (
        !is_dir($directory)
        && !mkdir($directory, 0775, true)
        && !is_dir($directory)
    ) {
        throw new RuntimeException(
            '添付ファイル用のフォルダを作成できませんでした。'
        );
    }
}

/**
 * 検証済みの一時ファイルを保存し、添付情報をデータベースへ記録する。
 *
 * @param array<int, array{
 *     upload_key: string,
 *     tmp_name: string,
 *     original_name: string,
 *     mime_type: string,
 *     media_kind: string,
 *     extension: string,
 *     size: int
 * }> $uploads
 * @return array<int, array{
 *     upload_key: string,
 *     path: string,
 *     stored_name: string
 * }>
 */
function savePostAttachments(
    PDO $pdo,
    int $postId,
    array $uploads,
    string $placement = 'gallery'
): array {
    if ($uploads === []) {
        return [];
    }

    ensurePostAttachmentDirectory();

    $statement = $pdo->prepare(
        '
        INSERT INTO post_attachments (
            post_id,
            stored_name,
            original_name,
            mime_type,
            media_kind,
            placement,
            display_order
        )
        VALUES (
            :post_id,
            :stored_name,
            :original_name,
            :mime_type,
            :media_kind,
            :placement,
            :display_order
        )
        '
    );

    $storedFiles = [];
    $displayOrder = 0;

    try {
        foreach ($uploads as $upload) {
            do {
                $storedName = bin2hex(random_bytes(16))
                    . '.'
                    . $upload['extension'];
                $destination = postAttachmentDirectory()
                    . '/'
                    . $storedName;
            } while (file_exists($destination));

            if (!move_uploaded_file($upload['tmp_name'], $destination)) {
                throw new RuntimeException(
                    '添付ファイルを保存できませんでした。'
                );
            }

            $storedFiles[] = [
                'upload_key' => (string) $upload['upload_key'],
                'path' => $destination,
                'stored_name' => $storedName,
            ];

            $statement->execute([
                ':post_id' => $postId,
                ':stored_name' => $storedName,
                ':original_name' => $upload['original_name'],
                ':mime_type' => $upload['mime_type'],
                ':media_kind' => $upload['media_kind'],
                ':placement' => $placement,
                ':display_order' => $displayOrder,
            ]);

            $displayOrder++;
        }
    } catch (Throwable $error) {
        foreach ($storedFiles as $storedFile) {
            if (is_file($storedFile['path'])) {
                unlink($storedFile['path']);
            }
        }

        throw $error;
    }

    return $storedFiles;
}

/**
 * @return array<int, array<string, mixed>>
 */
function postAttachmentsForPost(PDO $pdo, int $postId): array
{
    $statement = $pdo->prepare(
        '
        SELECT
            id,
            stored_name,
            original_name,
            mime_type,
            media_kind,
            placement,
            display_order
        FROM post_attachments
        WHERE post_id = :post_id
        ORDER BY display_order ASC, id ASC
        '
    );

    $statement->execute([
        ':post_id' => $postId,
    ]);

    return $statement->fetchAll();
}

function postAttachmentPublicUrl(
    string $storedName,
    string $relativePrefix = '../'
): string {
    return $relativePrefix
        . 'image/uploads/'
        . rawurlencode(basename($storedName));
}

function postAttachmentRootUrl(string $storedName): string
{
    return '/image/uploads/'
        . rawurlencode(basename($storedName));
}

function escapePostAttachmentHtml(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * @param array<int, array<string, mixed>> $attachments
 */
function renderPostAttachments(
    array $attachments,
    string $relativePrefix = '../'
): string {
    $galleryAttachments = array_values(
        array_filter(
            $attachments,
            static fn (array $attachment): bool =>
                ($attachment['placement'] ?? 'gallery') === 'gallery'
        )
    );

    if ($galleryAttachments === []) {
        return '';
    }

    $html = '<section class="post-attachments" aria-label="添付ファイル">';

    foreach ($galleryAttachments as $attachment) {
        $url = escapePostAttachmentHtml(
            postAttachmentPublicUrl(
                (string) $attachment['stored_name'],
                $relativePrefix
            )
        );
        $name = escapePostAttachmentHtml(
            (string) $attachment['original_name']
        );
        $mimeType = escapePostAttachmentHtml(
            (string) $attachment['mime_type']
        );

        if ($attachment['media_kind'] === 'image') {
            $html .=
                '<figure class="post-attachment post-attachment-image">'
                . '<a href="' . $url . '" target="_blank"'
                . ' rel="noopener noreferrer">'
                . '<img src="' . $url . '" alt="' . $name . '"'
                . ' loading="lazy" decoding="async">'
                . '</a>'
                . '</figure>';

            continue;
        }

        $html .=
            '<figure class="post-attachment post-attachment-video">'
            . '<video controls preload="metadata" playsinline>'
            . '<source src="' . $url . '" type="' . $mimeType . '">'
            . 'お使いのブラウザは動画の再生に対応していません。'
            . '</video>'
            . '</figure>';
    }

    return $html . '</section>';
}

/**
 * 投稿削除後に、対応する保存済みファイルを片付ける。
 *
 * @param array<int, array<string, mixed>> $attachments
 */
function deletePostAttachmentFiles(array $attachments): void
{
    foreach ($attachments as $attachment) {
        $storedName = basename((string) $attachment['stored_name']);
        $path = postAttachmentDirectory() . '/' . $storedName;

        if (is_file($path)) {
            unlink($path);
        }
    }
}
