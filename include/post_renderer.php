<?php

declare(strict_types=1);

/**
 * HTMLとして安全な文字列へ変換する。
 */
function escapePostHtml(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * X投稿URLを検出する正規表現。
 *
 * 対応例：
 * https://x.com/user/status/123456789
 * https://twitter.com/user/status/123456789
 */
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
 * 本文内にX投稿URLがあるか確認する。
 */
function containsXPostUrl(string $body): bool
{
    return preg_match(
        xPostUrlPattern(),
        $body
    ) === 1;
}

/**
 * 通常の文章部分をHTMLへ変換する。
 *
 * 一般的なURLはクリック可能なリンクへ変換する。
 */
function renderTextFragment(string $text): string
{
    $parts = preg_split(
        '~(https?://[^\s<]+)~iu',
        $text,
        -1,
        PREG_SPLIT_DELIM_CAPTURE
    );

    if ($parts === false) {
        return nl2br(
            escapePostHtml($text),
            false
        );
    }

    $html = '';

    foreach ($parts as $part) {
        if (
            preg_match('~^https?://~i', $part) === 1
            && filter_var(
                $part,
                FILTER_VALIDATE_URL
            ) !== false
        ) {
            $safeUrl = escapePostHtml($part);

            $html .=
                '<a href="' . $safeUrl . '"'
                . ' target="_blank"'
                . ' rel="noopener noreferrer">'
                . $safeUrl
                . '</a>';

            continue;
        }

        $html .= nl2br(
            escapePostHtml($part),
            false
        );
    }

    return $html;
}

/**
 * 投稿本文を表示用HTMLへ変換する。
 *
 * X投稿URLは埋め込み用の要素へ置き換える。
 */
function renderPostBody(string $body): string
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

        // X URLより前にある通常文章を表示
        $beforeText = substr(
            $body,
            $cursor,
            $matchedOffset - $cursor
        );

        $html .= renderTextFragment($beforeText);

        $safeUrl = escapePostHtml($matchedUrl);
        $safePostId = escapePostHtml($xPostId);

        // X埋め込み用の領域を作る
        $html .=
            '<div class="x-post-wrapper">'
            . '<div class="x-post-embed"'
            . ' data-x-post-id="' . $safePostId . '">'
            . '<p class="x-embed-fallback">'
            . 'X投稿を読み込んでいます。<br>'
            . '<a href="' . $safeUrl . '"'
            . ' target="_blank"'
            . ' rel="noopener noreferrer">'
            . 'Xで直接見る'
            . '</a>'
            . '</p>'
            . '</div>'
            . '</div>';

        $cursor =
            $matchedOffset
            + strlen($matchedUrl);
    }

    // 最後のX URLより後ろにある文章
    $remainingText = substr($body, $cursor);

    $html .= renderTextFragment($remainingText);

    return $html;
}