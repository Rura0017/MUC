<?php

declare(strict_types=1);

require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/post_renderer.php';

function makeExcerpt(
    string $body,
    string $bodyFormat,
    int $length = 150
): string
{
    $text = preg_replace(
        '~https?://\S+~u',
        '',
        postBodyPlainText($body, $bodyFormat)
    );
    $text = preg_replace('/\s+/u', ' ', trim($text ?? ''));

    if ($text === null || $text === '') {
        return '詳細ページで内容を確認できます。';
    }

    if (mb_strlen($text, 'UTF-8') <= $length) {
        return $text;
    }

    return mb_substr($text, 0, $length, 'UTF-8') . '…';
}

function formatPostDate(string $createdAt): string
{
    try {
        $date = new DateTime($createdAt, new DateTimeZone('UTC'));
        $date->setTimezone(new DateTimeZone('Asia/Tokyo'));

        return $date->format('Y/m/d H:i');
    } catch (Exception) {
        return $createdAt;
    }
}

$posts = db()
    ->query(
        '
        SELECT
            posts.id,
            posts.title,
            posts.body,
            posts.body_format,
            posts.created_at,
            COUNT(post_attachments.id) AS attachment_count
        FROM posts
        LEFT JOIN post_attachments
            ON post_attachments.post_id = posts.id
        WHERE posts.post_type = \'post\'
        GROUP BY posts.id
        ORDER BY posts.id DESC
        '
    )
    ->fetchAll();
?>

<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;600;700&amp;family=Zen+Maru+Gothic:wght@400;500&amp;display=swap">

  <link rel="stylesheet" href="../css/main.css?v=20260910-2">
  <link rel="stylesheet" href="../css/subpages.css?v=20260910-2">

  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
  >

  <title>投稿一覧 | MUC</title>

  <link
    rel="icon"
    href="../image/favicon.png"
    type="image/png"
  >
  <script src="../scripts/navigation.js?v=20260910-1" defer></script>
</head>

<body>

  <div class="site-wrapper subpage" id="page-top">
    <header class="site-header home-site-header subpage-header">
      <details class="site-navigation" open>
        <summary class="site-menu-toggle" aria-label="メニュー">
          <span class="site-menu-icon" aria-hidden="true"></span>
        </summary>
        <nav class="header-content" aria-label="メインメニュー">
          <ul class="header-menu">
            <li><a href="../index.html">TOP</a></li>
            <li><a href="act_menu.php">活動内容</a></li>
            <li><a href="purpose.html">目的</a></li>
            <li><a href="regulations.html">活動規定</a></li>
            <li><a href="join.html">加入方法</a></li>
            <li><a href="posts.php" aria-current="page">投稿一覧</a></li>
            <li><a href="login.php">ログイン</a></li>
          </ul>
        </nav>
      </details>
      <div class="mbody">
        <h1>
          <span class="subpage-brand">岡山理科大学 なんかしましょうサークル</span>
          投稿一覧
        </h1>
      </div>
    </header>
    <div class="page-home-return">
      <a class="home-return-link" href="../index.html">
        <span aria-hidden="true">←</span> ホームへ戻る
      </a>
    </div>

    <main class="subpage-main">
      <div class="post-list">
      <?php if ($posts === []): ?>
        <div class="sentence">
          <p>投稿はまだありません。</p>
        </div>
      <?php endif; ?>

      <?php foreach ($posts as $post): ?>
        <article class="post-list-card">
          <h2><?= escapePostHtml($post['title']) ?></h2>

          <p class="admin-post-date">
            <?= escapePostHtml(formatPostDate($post['created_at'])) ?>
          </p>

          <p><?= escapePostHtml(makeExcerpt(
              $post['body'],
              $post['body_format']
          )) ?></p>

          <?php if ((int) $post['attachment_count'] > 0): ?>
            <p class="post-attachment-indicator">
              画像・動画の添付: <?= (int) $post['attachment_count'] ?>件
            </p>
          <?php endif; ?>

          <a
            class="post-detail-link"
            href="post.php?id=<?= (int) $post['id'] ?>"
          >
            投稿を読む
          </a>
        </article>
      <?php endforeach; ?>
      </div>
    </main>

    <footer class="home-footer">
      <p class="home-footer-name">
        <img class="home-footer-icon" src="../image/favicon.png" width="64" height="64" alt="" decoding="async">
        <span>岡山理科大学<br>なんかしましょうサークル</span>
      </p>
      <a class="home-footer-top" href="#page-top">先頭へ戻る <span aria-hidden="true">↑</span></a>
      <p class="home-footer-contact">
        <span>HPに関する問い合わせ：</span>
        <span>ousmuc0315@gmail.com</span>
      </p>
      <small>© MUC</small>
    </footer>
  </div>

  <div class="link-wrapper">
    <a
      class="insta-link"
      href="https://www.instagram.com/ous_muc"
      target="_blank"
      rel="noopener noreferrer"
    >
      Instagram
    </a>

    <a
      class="x-link"
      href="https://x.com/ous_nannkasy0"
      target="_blank"
      rel="noopener noreferrer"
    >
      X.com
    </a>
  </div>

</body>
</html>
