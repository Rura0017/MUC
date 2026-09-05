<?php

declare(strict_types=1);

require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/post_renderer.php';
require_once __DIR__ . '/../include/post_attachments.php';

function h(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function formatActivityDate(string $createdAt): string
{
    try {
        $date = new DateTime(
            $createdAt,
            new DateTimeZone('UTC')
        );
        $date->setTimezone(new DateTimeZone('Asia/Tokyo'));

        return $date->format('Y/m/d');
    } catch (Exception) {
        return $createdAt;
    }
}

$pdo = db();

$projects = $pdo
    ->query(
        "
        SELECT
            id,
            title,
            body,
            body_format,
            created_at
        FROM posts
        WHERE post_type = 'activity'
        ORDER BY id DESC
        "
    )
    ->fetchAll();
?>

<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <link rel="stylesheet" href="../css/main.css">
  <link
    rel="canonical"
    href="https://ousmuc.motti-web.com/pages/act_menu.php"
  >
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
  >

  <title>MUC 活動内容・企画一覧</title>

  <meta property="og:title" content="MUC 活動内容・企画一覧">
  <meta
    property="og:description"
    content="岡山理科大学なんかしましょうサークルの活動内容と企画一覧"
  >
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="MUC">
  <meta
    property="og:url"
    content="https://ousmuc.motti-web.com/pages/act_menu.php"
  >
  <meta
    property="og:image"
    content="https://ousmuc.motti-web.com/image/ogp.png"
  >

  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="MUC 活動内容・企画一覧">
  <meta
    name="twitter:description"
    content="岡山理科大学なんかしましょうサークルの活動内容と企画一覧"
  >
  <meta
    name="twitter:image"
    content="https://ousmuc.motti-web.com/image/ogp.png"
  >

  <link rel="icon" href="../image/ogp.png" type="image/png">
</head>

<body class="loading">
  <div id="loading-screen">読み込み中...</div>

  <div class="site-wrapper">
    <div class="mbody">
      <h1>MUC<br>活動内容</h1>
    </div>

    <nav class="header-content">
      <ul class="header-menu">
        <li><a href="../index.html">トップ</a></li>
        <li><a href="join.html">加入方法</a></li>
        <li><a href="regulations.html">活動規定</a></li>
        <li><a href="posts.php">投稿一覧</a></li>
      </ul>
    </nav>

    <div class="member-row">
      <b>このページについて：</b>
      <span class="member">
        MUCで行った活動・進行中の企画・構想中の企画をまとめています。
      </span>
    </div>

    <section class="activity-project-section">
      <div class="activity-section-title">
        <h2>企画一覧</h2>
        <p>新しい企画から順に掲載しています。</p>
      </div>

      <?php if ($projects === []): ?>
        <div class="sentence activity-empty-state">
          <p>企画はまだありません。</p>
        </div>
      <?php endif; ?>

      <?php foreach ($projects as $project): ?>
        <?php $attachments = postAttachmentsForPost(
            $pdo,
            (int) $project['id']
        ); ?>

        <details class="activity-project-card">
          <summary>
            <span><?= h($project['title']) ?></span>
            <time datetime="<?= h($project['created_at']) ?>">
              <?= h(formatActivityDate($project['created_at'])) ?>
            </time>
          </summary>

          <div class="sentence activity-project-content">
            <div class="post-content">
              <?= renderPostBody(
                  $project['body'],
                  $project['body_format']
              ) ?>
            </div>

            <?= renderPostAttachments($attachments) ?>

            <p class="activity-project-actions">
              <a
                class="post-detail-link"
                href="post.php?id=<?= (int) $project['id'] ?>"
              >
                詳細ページを開く
              </a>
            </p>
          </div>
        </details>
      <?php endforeach; ?>
    </section>
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

  <?php if (array_filter(
      $projects,
      static fn (array $project): bool =>
          containsXPostUrl(
              $project['body'],
              $project['body_format']
          )
  ) !== []): ?>
    <script
      async
      src="https://platform.x.com/widgets.js"
      charset="utf-8"
    ></script>

    <script>
      function renderEmbeddedXPosts(attempt = 0) {
        if (
          !window.twttr ||
          !window.twttr.widgets ||
          !window.twttr.widgets.createTweet
        ) {
          if (attempt < 100) {
            window.setTimeout(function () {
              renderEmbeddedXPosts(attempt + 1);
            }, 100);
          }

          return;
        }

        document
          .querySelectorAll(".x-post-embed[data-x-post-id]")
          .forEach(function (container) {
            if (container.dataset.xLoaded === "true") {
              return;
            }

            container.dataset.xLoaded = "true";

            window.twttr.widgets.createTweet(
              container.dataset.xPostId,
              container,
              {
                align: "center",
                dnt: true,
                theme: "light",
                conversation: "none"
              }
            ).then(function (element) {
              if (!element) {
                container.dataset.xLoaded = "false";
                return;
              }

              const fallback = container.querySelector(
                ".x-embed-fallback"
              );

              if (fallback) {
                fallback.remove();
              }
            }).catch(function (error) {
              container.dataset.xLoaded = "false";
              console.error("X投稿の埋め込みに失敗しました。", error);
            });
          });
      }

      renderEmbeddedXPosts();
    </script>
  <?php endif; ?>

  <script>
    function finishLoading() {
      document.body.classList.remove("loading");
      document.body.classList.add("loaded");
    }

    if (document.readyState === "loading") {
      document.addEventListener(
        "DOMContentLoaded",
        finishLoading,
        { once: true }
      );
    } else {
      finishLoading();
    }

    window.setTimeout(finishLoading, 3000);
  </script>
</body>
</html>
