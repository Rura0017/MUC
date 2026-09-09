<?php

declare(strict_types=1);

require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/post_renderer.php';

function h(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

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
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;600;700&amp;display=swap">

  <link rel="stylesheet" href="../css/main.css?v=20260909-10">

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
  <script src="../scripts/navigation.js?v=20260906-2" defer></script>
</head>

<body>

  <div class="site-wrapper">
    <header class="site-header">
      <div class="mbody">
      <h1>MUC<br>投稿一覧</h1>
    </div>
      <details class="site-navigation" open>
        <summary class="site-menu-toggle" aria-label="メニュー">
          <span class="site-menu-icon" aria-hidden="true"></span>
        </summary>
        <nav class="header-content" aria-label="メインメニュー">
          <ul class="header-menu">
            <li><a href="act_menu.php">活動内容</a></li>
            <li><a href="purpose.html">目的</a></li>
            <li><a href="regulations.html">活動規定</a></li>
            <li><a href="join.html">加入方法</a></li>
            <li><a href="posts.php" aria-current="page">投稿一覧</a></li>
            <li><a href="login.php">ログイン</a></li>
          </ul>
        </nav>
      </details>
    </header>
    <div class="page-home-return">
      <a class="home-return-link" href="../index.html">
        <span aria-hidden="true">←</span> ホームへ戻る
      </a>
    </div>




    <main class="post-list">
      <?php if ($posts === []): ?>
        <div class="sentence">
          <p>投稿はまだありません。</p>
        </div>
      <?php endif; ?>

      <?php foreach ($posts as $post): ?>
        <article class="post-list-card">
          <h2><?= h($post['title']) ?></h2>

          <p class="admin-post-date">
            <?= h(formatPostDate($post['created_at'])) ?>
          </p>

          <p><?= h(makeExcerpt(
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
    </main>
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
