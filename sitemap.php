<?php

declare(strict_types=1);

require_once __DIR__ . '/include/db.php';

header('Content-Type: application/xml; charset=UTF-8');

const SITE_ORIGIN = 'https://ousmuc.motti-web.com';

function escapeSitemapXml(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function sitemapLastModified(string $value): ?string
{
    try {
        return (new DateTime(
            $value,
            new DateTimeZone('UTC')
        ))->format(DateTimeInterface::ATOM);
    } catch (Exception) {
        return null;
    }
}

$staticPaths = [
    '/',
    '/pages/act_menu.php',
    '/pages/purpose.html',
    '/pages/regulations.html',
    '/pages/join.html',
    '/pages/posts.php',
];

$posts = db()
    ->query(
        '
        SELECT
            id,
            created_at,
            updated_at
        FROM posts
        ORDER BY id ASC
        '
    )
    ->fetchAll();

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <?php foreach ($staticPaths as $path): ?>
  <url>
    <loc><?= escapeSitemapXml(SITE_ORIGIN . $path) ?></loc>
  </url>
  <?php endforeach; ?>
  <?php foreach ($posts as $post): ?>
  <url>
    <loc><?= escapeSitemapXml(
        SITE_ORIGIN . '/pages/post.php?id=' . (int) $post['id']
    ) ?></loc>
    <?php $lastModified = sitemapLastModified(
        $post['updated_at'] ?: $post['created_at']
    ); ?>
    <?php if ($lastModified !== null): ?>
    <lastmod><?= escapeSitemapXml($lastModified) ?></lastmod>
    <?php endif; ?>
  </url>
  <?php endforeach; ?>
</urlset>
