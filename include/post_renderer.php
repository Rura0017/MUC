<?php

declare(strict_types=1);

const POST_BODY_FORMAT_PLAIN = 'plain';
const POST_BODY_FORMAT_QUILL = 'quill';
const POST_BODY_MAX_TEXT_LENGTH = 10000;
const POST_BODY_MAX_JSON_LENGTH = 200000;
const POST_BODY_MAX_OPERATIONS = 2000;

function escapePostHtml(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function xPostUrlPattern(): string
{
    return
        '~https?://'
        . '(?:www\.)?'
        . '(?:x\.com|twitter\.com)'
        . '/[A-Za-z0-9_]+'
        . '/status/'
        . '([0-9]{1,20})'
        . '(?:[/?#][^\s<]*)?'
        . '~iu';
}

/**
 * @return array{ops: array<int, array<string, mixed>>}
 */
function decodeQuillBody(string $body): array
{
    if (
        $body === ''
        || strlen($body) > POST_BODY_MAX_JSON_LENGTH
    ) {
        throw new RuntimeException(
            '本文のデータ量が上限を超えています。'
        );
    }

    try {
        $document = json_decode(
            $body,
            true,
            64,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        throw new RuntimeException(
            '本文の形式を確認できませんでした。'
        );
    }

    if (
        !is_array($document)
        || !isset($document['ops'])
        || !is_array($document['ops'])
        || !array_is_list($document['ops'])
        || count($document['ops']) > POST_BODY_MAX_OPERATIONS
    ) {
        throw new RuntimeException(
            '本文の形式を確認できませんでした。'
        );
    }

    return ['ops' => $document['ops']];
}

function normalizeQuillColor(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = strtolower(trim($value));

    if (
        preg_match(
            '/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/',
            $value
        ) !== 1
    ) {
        return null;
    }

    return $value;
}

function normalizeQuillLink(mixed $value): ?string
{
    if (!is_string($value) || strlen($value) > 2048) {
        return null;
    }

    $value = trim($value);
    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

    if (!in_array($scheme, ['http', 'https', 'mailto'], true)) {
        return null;
    }

    return $value;
}

/**
 * @param array<string, mixed> $attributes
 * @return array<string, mixed>
 */
function normalizeQuillAttributes(array $attributes): array
{
    $normalized = [];

    foreach (['bold', 'italic', 'underline', 'strike'] as $format) {
        if (($attributes[$format] ?? false) === true) {
            $normalized[$format] = true;
        }
    }

    foreach (['color', 'background'] as $format) {
        $color = normalizeQuillColor($attributes[$format] ?? null);

        if ($color !== null) {
            $normalized[$format] = $color;
        }
    }

    $link = normalizeQuillLink($attributes['link'] ?? null);

    if ($link !== null) {
        $normalized['link'] = $link;
    }

    $size = $attributes['size'] ?? null;

    if (
        is_string($size)
        && in_array($size, ['small', 'large', 'huge'], true)
    ) {
        $normalized['size'] = $size;
    }

    $header = $attributes['header'] ?? null;

    if (in_array($header, [2, 3], true)) {
        $normalized['header'] = $header;
    }

    $list = $attributes['list'] ?? null;

    if (
        is_string($list)
        && in_array($list, ['ordered', 'bullet'], true)
    ) {
        $normalized['list'] = $list;
    }

    if (($attributes['blockquote'] ?? false) === true) {
        $normalized['blockquote'] = true;
    }

    $align = $attributes['align'] ?? null;

    if (
        is_string($align)
        && in_array($align, ['center', 'right', 'justify'], true)
    ) {
        $normalized['align'] = $align;
    }

    $indent = filter_var(
        $attributes['indent'] ?? null,
        FILTER_VALIDATE_INT
    );

    if ($indent !== false && $indent >= 1 && $indent <= 4) {
        $normalized['indent'] = $indent;
    }

    return $normalized;
}

function isStoredInlineImageUrl(string $value): bool
{
    return preg_match(
        '~^/image/uploads/[a-f0-9]{32}\.(?:jpg|png|gif|webp)$~',
        $value
    ) === 1;
}

/**
 * @param array<int, string> $allowedUploadTokens
 */
function normalizeQuillBody(
    string $body,
    array $allowedUploadTokens = [],
    bool $allowStoredImages = false
): string {
    $document = decodeQuillBody($body);
    $allowedTokenLookup = array_fill_keys(
        $allowedUploadTokens,
        true
    );
    $normalizedOperations = [];
    $textLength = 0;

    foreach ($document['ops'] as $operation) {
        if (!is_array($operation) || !array_key_exists('insert', $operation)) {
            throw new RuntimeException(
                '本文に対応していない内容が含まれています。'
            );
        }

        $insert = $operation['insert'];
        $attributes = is_array($operation['attributes'] ?? null)
            ? normalizeQuillAttributes($operation['attributes'])
            : [];

        if (is_string($insert)) {
            $insert = str_replace(["\r\n", "\r"], "\n", $insert);
            $textLength += mb_strlen($insert, 'UTF-8');

            if ($textLength > POST_BODY_MAX_TEXT_LENGTH) {
                throw new RuntimeException(
                    '本文は10000文字以内にしてください。'
                );
            }

            if ($insert === '') {
                continue;
            }

            $normalizedOperation = ['insert' => $insert];

            if ($attributes !== []) {
                $normalizedOperation['attributes'] = $attributes;
            }

            $normalizedOperations[] = $normalizedOperation;
            continue;
        }

        if (
            !is_array($insert)
            || count($insert) !== 1
            || !isset($insert['image'])
            || !is_string($insert['image'])
        ) {
            throw new RuntimeException(
                '本文に対応していない埋め込みが含まれています。'
            );
        }

        $imageSource = $insert['image'];
        $isAllowedUpload = false;

        if (str_starts_with($imageSource, 'upload:')) {
            $token = substr($imageSource, 7);
            $isAllowedUpload =
                preg_match('/^[A-Za-z0-9_-]{1,64}$/', $token) === 1
                && isset($allowedTokenLookup[$token]);
        }

        if (
            !$isAllowedUpload
            && !(
                $allowStoredImages
                && isStoredInlineImageUrl($imageSource)
            )
        ) {
            throw new RuntimeException(
                '本文内の画像をもう一度選択してください。'
            );
        }

        $normalizedOperations[] = [
            'insert' => ['image' => $imageSource],
        ];
    }

    if ($normalizedOperations === []) {
        $normalizedOperations[] = ['insert' => "\n"];
    }

    return json_encode(
        ['ops' => $normalizedOperations],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_THROW_ON_ERROR
    );
}

/**
 * @return array<int, string>
 */
function quillInlineImageTokens(string $body): array
{
    $document = decodeQuillBody($body);
    $tokens = [];

    foreach ($document['ops'] as $operation) {
        $source = $operation['insert']['image'] ?? null;

        if (!is_string($source) || !str_starts_with($source, 'upload:')) {
            continue;
        }

        $tokens[] = substr($source, 7);
    }

    return array_values(array_unique($tokens));
}

/**
 * @param array<string, string> $imageUrlsByToken
 */
function replaceQuillImageUploads(
    string $body,
    array $imageUrlsByToken
): string {
    $document = decodeQuillBody($body);

    foreach ($document['ops'] as &$operation) {
        $source = $operation['insert']['image'] ?? null;

        if (!is_string($source) || !str_starts_with($source, 'upload:')) {
            continue;
        }

        $token = substr($source, 7);

        if (!isset($imageUrlsByToken[$token])) {
            throw new RuntimeException(
                '本文内の画像を保存できませんでした。'
            );
        }

        $operation['insert']['image'] = $imageUrlsByToken[$token];
    }
    unset($operation);

    return normalizeQuillBody(
        json_encode(
            $document,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        ),
        [],
        true
    );
}

function postBodyPlainText(
    string $body,
    string $bodyFormat = POST_BODY_FORMAT_PLAIN
): string {
    if ($bodyFormat !== POST_BODY_FORMAT_QUILL) {
        return $body;
    }

    try {
        $document = decodeQuillBody($body);
    } catch (RuntimeException) {
        return '';
    }

    $text = '';

    foreach ($document['ops'] as $operation) {
        if (is_string($operation['insert'] ?? null)) {
            $text .= $operation['insert'];
        }
    }

    return $text;
}

function postBodyHasContent(
    string $body,
    string $bodyFormat = POST_BODY_FORMAT_PLAIN
): bool {
    if ($bodyFormat !== POST_BODY_FORMAT_QUILL) {
        return trim($body) !== '';
    }

    try {
        $document = decodeQuillBody($body);
    } catch (RuntimeException) {
        return false;
    }

    foreach ($document['ops'] as $operation) {
        $insert = $operation['insert'] ?? null;

        if (is_string($insert) && trim($insert) !== '') {
            return true;
        }

        if (is_array($insert) && isset($insert['image'])) {
            return true;
        }
    }

    return false;
}

function containsXPostUrl(
    string $body,
    string $bodyFormat = POST_BODY_FORMAT_PLAIN
): bool {
    return preg_match(
        xPostUrlPattern(),
        postBodyPlainText($body, $bodyFormat)
    ) === 1;
}

function renderTextFragment(string $text): string
{
    $parts = preg_split(
        '~(https?://[^\s<]+)~iu',
        $text,
        -1,
        PREG_SPLIT_DELIM_CAPTURE
    );

    if ($parts === false) {
        return nl2br(escapePostHtml($text), false);
    }

    $html = '';

    foreach ($parts as $part) {
        if (
            preg_match('~^https?://~i', $part) === 1
            && filter_var($part, FILTER_VALIDATE_URL) !== false
        ) {
            $safeUrl = escapePostHtml($part);
            $html .=
                '<a href="' . $safeUrl . '"'
                . ' target="_blank" rel="noopener noreferrer">'
                . $safeUrl
                . '</a>';
            continue;
        }

        $html .= nl2br(escapePostHtml($part), false);
    }

    return $html;
}

function renderXPostEmbed(string $url, string $postId): string
{
    $safeUrl = escapePostHtml($url);
    $safePostId = escapePostHtml($postId);

    return
        '<div class="x-post-wrapper">'
        . '<div class="x-post-embed" data-x-post-id="'
        . $safePostId . '">'
        . '<p class="x-embed-fallback">'
        . 'X投稿を読み込んでいます。<br>'
        . '<a href="' . $safeUrl . '" target="_blank"'
        . ' rel="noopener noreferrer">Xで直接見る</a>'
        . '</p></div></div>';
}

function renderPlainPostBody(string $body): string
{
    $pattern = xPostUrlPattern();
    $matchCount = preg_match_all(
        $pattern,
        $body,
        $matches,
        PREG_OFFSET_CAPTURE
    );

    if ($matchCount === false || $matchCount === 0) {
        return renderTextFragment($body);
    }

    $html = '';
    $cursor = 0;

    for ($i = 0; $i < $matchCount; $i++) {
        $matchedUrl = $matches[0][$i][0];
        $matchedOffset = $matches[0][$i][1];
        $xPostId = $matches[1][$i][0];

        $html .= renderTextFragment(
            substr($body, $cursor, $matchedOffset - $cursor)
        );
        $html .= renderXPostEmbed($matchedUrl, $xPostId);
        $cursor = $matchedOffset + strlen($matchedUrl);
    }

    return $html . renderTextFragment(substr($body, $cursor));
}

/**
 * @param array<string, mixed> $attributes
 */
function renderQuillInline(string $text, array $attributes): string
{
    $link = normalizeQuillLink($attributes['link'] ?? null);
    $html = $link === null
        ? renderTextFragment($text)
        : escapePostHtml($text);

    foreach (
        [
            'bold' => 'strong',
            'italic' => 'em',
            'underline' => 'u',
            'strike' => 's',
        ] as $format => $tag
    ) {
        if (($attributes[$format] ?? false) === true) {
            $html = '<' . $tag . '>' . $html . '</' . $tag . '>';
        }
    }

    $classes = [];
    $size = $attributes['size'] ?? null;

    if (
        is_string($size)
        && in_array($size, ['small', 'large', 'huge'], true)
    ) {
        $classes[] = 'rich-size-' . $size;
    }

    $styles = [];
    $color = normalizeQuillColor($attributes['color'] ?? null);
    $background = normalizeQuillColor($attributes['background'] ?? null);

    if ($color !== null) {
        $styles[] = 'color:' . $color;
    }

    if ($background !== null) {
        $styles[] = 'background-color:' . $background;
    }

    if ($classes !== [] || $styles !== []) {
        $html = '<span'
            . ($classes === []
                ? ''
                : ' class="' . implode(' ', $classes) . '"')
            . ($styles === []
                ? ''
                : ' style="' . implode(';', $styles) . '"')
            . '>' . $html . '</span>';
    }

    if ($link !== null) {
        $safeLink = escapePostHtml($link);
        $html = '<a href="' . $safeLink . '" target="_blank"'
            . ' rel="noopener noreferrer">' . $html . '</a>';
    }

    return $html;
}

/**
 * @param array<string, mixed> $attributes
 * @param array<int, string> $baseClasses
 */
function quillBlockClass(
    array $attributes,
    array $baseClasses = []
): string
{
    $classes = $baseClasses;
    $align = $attributes['align'] ?? null;

    if (in_array($align, ['center', 'right', 'justify'], true)) {
        $classes[] = 'rich-align-' . $align;
    }

    $indent = $attributes['indent'] ?? null;

    if (is_int($indent) && $indent >= 1 && $indent <= 4) {
        $classes[] = 'rich-indent-' . $indent;
    }

    return $classes === []
        ? ''
        : ' class="' . implode(' ', $classes) . '"';
}

/**
 * @param array{html: string, text: string, attributes: array<string, mixed>, has_media: bool} $line
 */
function renderQuillBlock(array $line): string
{
    $attributes = $line['attributes'];
    $content = $line['html'] === '' ? '<br>' : $line['html'];
    $trimmedText = trim($line['text']);

    if (!$line['has_media'] && $trimmedText !== '') {
        $matched = preg_match(
            xPostUrlPattern(),
            $trimmedText,
            $matches
        );

        if ($matched === 1 && $matches[0] === $trimmedText) {
            return renderXPostEmbed($matches[0], $matches[1]);
        }
    }

    $class = quillBlockClass($attributes);
    $header = $attributes['header'] ?? null;

    if (in_array($header, [2, 3], true)) {
        return '<h' . $header . $class . '>'
            . $content
            . '</h' . $header . '>';
    }

    if (($attributes['blockquote'] ?? false) === true) {
        return '<blockquote' . $class . '>'
            . $content
            . '</blockquote>';
    }

    if ($line['has_media']) {
        return '<div'
            . quillBlockClass($attributes, ['rich-media-line'])
            . '>'
            . $content
            . '</div>';
    }

    return '<p' . $class . '>' . $content . '</p>';
}

function renderQuillPostBody(string $body): string
{
    try {
        $document = decodeQuillBody($body);
    } catch (RuntimeException) {
        return '';
    }

    $lines = [];
    $currentHtml = '';
    $currentText = '';
    $hasMedia = false;

    $finishLine = static function (array $attributes) use (
        &$lines,
        &$currentHtml,
        &$currentText,
        &$hasMedia
    ): void {
        $lines[] = [
            'html' => $currentHtml,
            'text' => $currentText,
            'attributes' => normalizeQuillAttributes($attributes),
            'has_media' => $hasMedia,
        ];
        $currentHtml = '';
        $currentText = '';
        $hasMedia = false;
    };

    foreach ($document['ops'] as $operation) {
        $insert = $operation['insert'] ?? null;
        $attributes = is_array($operation['attributes'] ?? null)
            ? $operation['attributes']
            : [];

        if (is_array($insert) && isset($insert['image'])) {
            $source = (string) $insert['image'];

            if (!isStoredInlineImageUrl($source)) {
                continue;
            }

            $safeSource = escapePostHtml($source);
            $currentHtml .=
                '<figure class="rich-inline-image">'
                . '<a href="' . $safeSource . '" target="_blank"'
                . ' rel="noopener noreferrer">'
                . '<img src="' . $safeSource . '" alt=""'
                . ' loading="lazy" decoding="async">'
                . '</a></figure>';
            $hasMedia = true;
            continue;
        }

        if (!is_string($insert)) {
            continue;
        }

        $parts = explode("\n", $insert);
        $lastIndex = count($parts) - 1;

        foreach ($parts as $index => $part) {
            if ($part !== '') {
                $currentHtml .= renderQuillInline($part, $attributes);
                $currentText .= $part;
            }

            if ($index !== $lastIndex) {
                $finishLine($attributes);
            }
        }
    }

    if ($currentHtml !== '' || $currentText !== '' || $hasMedia) {
        $finishLine([]);
    }

    $html = '<div class="rich-post-body">';
    $openList = null;

    foreach ($lines as $line) {
        $list = $line['attributes']['list'] ?? null;

        if (in_array($list, ['ordered', 'bullet'], true)) {
            $listTag = $list === 'ordered' ? 'ol' : 'ul';

            if ($openList !== $listTag) {
                if ($openList !== null) {
                    $html .= '</' . $openList . '>';
                }

                $html .= '<' . $listTag . '>';
                $openList = $listTag;
            }

            $content = $line['html'] === '' ? '<br>' : $line['html'];
            $html .= '<li' . quillBlockClass($line['attributes']) . '>'
                . $content . '</li>';
            continue;
        }

        if ($openList !== null) {
            $html .= '</' . $openList . '>';
            $openList = null;
        }

        $html .= renderQuillBlock($line);
    }

    if ($openList !== null) {
        $html .= '</' . $openList . '>';
    }

    return $html . '</div>';
}

function renderPostBody(
    string $body,
    string $bodyFormat = POST_BODY_FORMAT_PLAIN
): string {
    if ($bodyFormat === POST_BODY_FORMAT_QUILL) {
        return renderQuillPostBody($body);
    }

    return renderPlainPostBody($body);
}
