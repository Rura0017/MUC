<?php

declare(strict_types=1);

require_once __DIR__ . '/include/security.php';

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
    // 空の日時から現在時刻を生成しない。
    if (trim($value) === '') {
        return null;
    }

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

$posts = [];

try {
    $databasePath = getenv('MUC_DATABASE_PATH') ?: __DIR__ . '/storage/muc.sqlite';

    if (!is_file($databasePath)) {
        throw new RuntimeException('Sitemap database is unavailable.');
    }

    // 読み取り専用で開き、サイトマップ取得時にDB作成やデータ移行を行わない。
    $pdo = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
        PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
    ]);

    $columns = $pdo->query('PRAGMA table_info(posts)')->fetchAll();
    // 更新日時の列がない旧DBでは、公開日時を最終更新日時として使う。
    $updatedAtColumn = in_array('updated_at', array_column($columns, 'name'), true)
        ? 'updated_at'
        : 'created_at';

    $posts = $pdo
        ->query(
            'SELECT id, created_at, ' . $updatedAtColumn . ' AS updated_at
             FROM posts
             ORDER BY id ASC'
        )
        ->fetchAll();
} catch (Throwable) {
    // DBを読めない場合も主要ページを案内し、内部情報は外へ出さない。
    error_log('Sitemap database read failed.');
}

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
