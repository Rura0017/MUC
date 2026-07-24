<?php

declare(strict_types=1);

/**
 * Xの投稿URLから投稿IDを取得する。
 *
 * 対応例:
 * https://x.com/example/status/1234567890
 * https://twitter.com/example/status/1234567890
 */
function extractXPostId(string $url): ?string
{
    $url = trim($url);

    if ($url === '') {
        return null;
    }

    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }

    $parts = parse_url($url);

    if ($parts === false) {
        return null;
    }

    $host = strtolower($parts['host'] ?? '');

    $allowedHosts = [
        'x.com',
        'www.x.com',
        'twitter.com',
        'www.twitter.com',
        'mobile.twitter.com',
    ];

    if (!in_array($host, $allowedHosts, true)) {
        return null;
    }

    $path = $parts['path'] ?? '';

    if (
        preg_match(
            '~\/status\/([0-9]{1,19})(?:\/|$)~',
            $path,
            $matches
        ) !== 1
    ) {
        return null;
    }

    return $matches[1];
}