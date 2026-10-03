<?php

declare(strict_types=1);

require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/post_renderer.php';
require_once __DIR__ . '/../include/post_attachments.php';

// URLのidパラメーターから投稿IDを取得する
$postId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if ($postId === false || $postId === null) {
    http_response_code(404);
    exit('投稿が見つかりません。');
}

$pdo = db();

$stmt = $pdo->prepare(
    '
    SELECT
        id,
        title,
        body,
        body_format,
        post_type,
        created_at
    FROM posts
    WHERE id = :id
    '
);

$stmt->execute([
    ':id' => $postId,
]);

$post = $stmt->fetch();

if ($post === false) {
    http_response_code(404);
    exit('投稿が見つかりません。');
}

$attachments = postAttachmentsForPost(
    $pdo,
    (int) $post['id']
);

$isProject = $post['post_type'] === 'activity';

// Xカードなどに表示する概要文を作る
$descriptionText = postBodyPlainText(
    $post['body'],
    $post['body_format']
);

$description = preg_replace(
    '~https?://\S+~u',
    '',
    $descriptionText
);

$description = preg_replace(
    '/\s+/u',
    ' ',
    trim($description ?? '')
);

if ($description === null) {
    $description = '';
}

if (mb_strlen($description, 'UTF-8') > 120) {
    $description =
        mb_substr(
            $description,
            0,
            120,
            'UTF-8'
        )
        . '…';
}

$canonicalUrl =
    'https://ousmuc.motti-web.com/pages/post.php?id='
    . (int) $post['id'];

$ogImageUrl =
    'https://ousmuc.motti-web.com/image/ogp.png';

$shareUrl =
    'https://x.com/intent/tweet?'
    . http_build_query([
        'text' => $post['title'] . ' | MUC',
        'url' => $canonicalUrl,
    ]);
?>

<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;600;700&amp;family=Zen+Maru+Gothic:wght@400;500&amp;display=swap">

  <link
    rel="stylesheet"
    href="../css/main.css?v=20261003-7"
  >
  <link rel="stylesheet" href="../css/subpages.css?v=20260910-2">

  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
  >

  <title><?= escapePostHtml($post['title']) ?> | MUC</title>

  <link
    rel="canonical"
    href="<?= escapePostHtml($canonicalUrl) ?>"
  >

  <link
    rel="icon"
    href="../image/favicon.png"
    type="image/png"
  >

  <!-- Open Graph -->
  <meta
    property="og:title"
    content="<?= escapePostHtml($post['title']) ?>"
  >

  <meta
    property="og:description"
    content="<?= escapePostHtml($description) ?>"
  >

  <meta
    property="og:type"
    content="article"
  >

  <meta
    property="og:url"
    content="<?= escapePostHtml($canonicalUrl) ?>"
  >

  <meta
    property="og:image"
    content="<?= escapePostHtml($ogImageUrl) ?>"
  >

  <meta
    property="og:site_name"
    content="MUC"
  >

  <!-- Xカード -->
  <meta
    name="twitter:card"
    content="summary_large_image"
  >

  <meta
    name="twitter:site"
    content="@ous_nannkasy0"
  >

  <meta
    name="twitter:title"
    content="<?= escapePostHtml($post['title']) ?>"
  >

  <meta
    name="twitter:description"
    content="<?= escapePostHtml($description) ?>"
  >

  <meta
    name="twitter:image"
    content="<?= escapePostHtml($ogImageUrl) ?>"
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
            <li><a href="act_menu.php"<?= $isProject ? ' aria-current="location"' : '' ?>>活動内容</a></li>
            <li><a href="purpose.html">目的</a></li>
            <li><a href="regulations.html">活動規定</a></li>
            <li><a href="join.html">加入方法</a></li>
            <li><a href="posts.php"<?= !$isProject ? ' aria-current="location"' : '' ?>>投稿一覧</a></li>
            <li><a href="login.php">ログイン</a></li>
          </ul>
        </nav>
      </details>
      <div class="mbody">
        <h1>
          <span class="subpage-brand">岡山理科大学 なんかしましょうサークル</span>
          <?= $isProject ? '企画' : '投稿' ?>
        </h1>
      </div>
    </header>
    <div class="page-home-return">
      <a class="home-return-link" href="../index.html">
        <span aria-hidden="true">←</span> ホームへ戻る
      </a>
    </div>

    <main class="subpage-main">
    <article class="public-post" aria-labelledby="post-title">
      <header class="page-lead">
      <?php if ($isProject): ?>
        <p class="content-type-badge content-type-activity">
          企画
        </p>
      <?php endif; ?>

      <h2 id="post-title">
        <?= escapePostHtml($post['title']) ?>
      </h2>

      <p class="admin-post-date">
        公開日：
        <?= escapePostHtml(
            formatPostDate(
                $post['created_at']
            )
        ) ?>
      </p>
      </header>

      <div class="sentence post-content">
        <?= renderPostBody(
            $post['body'],
            $post['body_format']
        ) ?>
      </div>

      <?= renderPostAttachments($attachments) ?>

      <div class="public-post-actions">
        <a
          class="share-button"
          href="<?= escapePostHtml($shareUrl) ?>"
          target="_blank"
          rel="noopener noreferrer"
        >
          この<?= $isProject ? '企画' : '投稿' ?>をXで共有
        </a>
        <a class="subpage-back-link" href="<?= $isProject ? 'act_menu.php' : 'posts.php' ?>">
          <span aria-hidden="true">←</span> <?= $isProject ? '活動内容' : '投稿一覧' ?>へ戻る
        </a>
      </div>
    </article>
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

  <?php if (containsXPostUrl(
      $post['body'],
      $post['body_format']
  )): ?>
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

        const containers =
          document.querySelectorAll(
            ".x-post-embed[data-x-post-id]"
          );

        containers.forEach(function (container) {
          if (container.dataset.xLoaded === "true") {
            return;
          }

          container.dataset.xLoaded = "true";

          const xPostId =
            container.dataset.xPostId;

          window.twttr.widgets.createTweet(
            xPostId,
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

            const fallback =
              container.querySelector(
                ".x-embed-fallback"
              );

            if (fallback) {
              fallback.remove();
            }
          }).catch(function (error) {
            container.dataset.xLoaded = "false";

            console.error(
              "X投稿の埋め込みに失敗しました。",
              error
            );
          });
        });
      }

      renderEmbeddedXPosts();
    </script>
  <?php endif; ?>

</body>
</html>
