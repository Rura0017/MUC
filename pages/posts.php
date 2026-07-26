<?php

declare(strict_types=1);

require_once __DIR__ . '/../include/db.php';

function h(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function makeExcerpt(string $body, int $length = 150): string
{
    $text = preg_replace('~https?://\S+~u', '', $body);
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
            id,
            title,
            body,
            created_at
        FROM posts
        ORDER BY id DESC
        '
    )
    ->fetchAll();
?>

<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">

  <link rel="stylesheet" href="../css/main.css">

  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
  >

  <title>投稿一覧 | MUC</title>

  <link
    rel="icon"
    href="../image/ogp.png"
    type="image/png"
  >
</head>

<body class="loading">
  <div id="loading-screen">読み込み中...</div>

  <div class="site-wrapper">
    <div class="mbody">
      <h1>MUC<br>投稿一覧</h1>
    </div>

    <nav class="header-content">
      <ul class="header-menu">
        <li><a href="../index.html">トップに戻る</a></li>
      </ul>
    </nav>

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

          <p><?= h(makeExcerpt($post['body'])) ?></p>

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

  <script>
    window.addEventListener("load", function () {
      document.body.classList.remove("loading");
      document.body.classList.add("loaded");
    });
  </script>
</body>
</html>
