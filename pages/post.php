<?php

declare(strict_types=1);

require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/post_renderer.php';
require_once __DIR__ . '/../include/post_attachments.php';

/**
 * HTMLとして解釈される特殊文字を無害化する。
 */
function h(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * SQLiteの日時を日本時間へ変換する。
 */
function formatPostDate(string $createdAt): string
{
    try {
        $date = new DateTime(
            $createdAt,
            new DateTimeZone('UTC')
        );

        $date->setTimezone(
            new DateTimeZone('Asia/Tokyo')
        );

        return $date->format('Y/m/d H:i');
    } catch (Exception) {
        return $createdAt;
    }
}

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
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;600;700&amp;display=swap">

  <link
    rel="stylesheet"
    href="../css/main.css?v=20260909-10"
  >

  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
  >

  <title><?= h($post['title']) ?> | MUC</title>

  <link
    rel="canonical"
    href="<?= h($canonicalUrl) ?>"
  >

  <link
    rel="icon"
    href="../image/favicon.png"
    type="image/png"
  >

  <!-- Open Graph -->
  <meta
    property="og:title"
    content="<?= h($post['title']) ?>"
  >

  <meta
    property="og:description"
    content="<?= h($description) ?>"
  >

  <meta
    property="og:type"
    content="article"
  >

  <meta
    property="og:url"
    content="<?= h($canonicalUrl) ?>"
  >

  <meta
    property="og:image"
    content="<?= h($ogImageUrl) ?>"
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
    content="<?= h($post['title']) ?>"
  >

  <meta
    name="twitter:description"
    content="<?= h($description) ?>"
  >

  <meta
    name="twitter:image"
    content="<?= h($ogImageUrl) ?>"
  >
  <script src="../scripts/navigation.js?v=20260910-1" defer></script>
</head>

<body>
  <div class="site-wrapper">
    <header class="site-header">
      <div class="mbody">
      <h1>
        MUC<br>
        <?= $isProject ? '企画' : '投稿' ?>
      </h1>
    </div>
      <details class="site-navigation" open>
        <summary class="site-menu-toggle" aria-label="メニュー">
          <span class="site-menu-icon" aria-hidden="true"></span>
        </summary>
        <nav class="header-content" aria-label="メインメニュー">
          <ul class="header-menu">
            <li><a href="act_menu.php"<?= $isProject ? ' aria-current="location"' : '' ?>>活動内容</a></li>
            <li><a href="purpose.html">目的</a></li>
            <li><a href="regulations.html">活動規定</a></li>
            <li><a href="join.html">加入方法</a></li>
            <li><a href="posts.php"<?= !$isProject ? ' aria-current="location"' : '' ?>>投稿一覧</a></li>
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




    <article class="public-post">
      <?php if ($isProject): ?>
        <p class="content-type-badge content-type-activity">
          企画
        </p>
      <?php endif; ?>

      <h2>
        <?= h($post['title']) ?>
      </h2>

      <p class="admin-post-date">
        公開日：
        <?= h(
            formatPostDate(
                $post['created_at']
            )
        ) ?>
      </p>

      <div class="sentence post-content">
        <?= renderPostBody(
            $post['body'],
            $post['body_format']
        ) ?>
      </div>

      <?= renderPostAttachments($attachments) ?>

      <p>
        <a
          class="share-button"
          href="<?= h($shareUrl) ?>"
          target="_blank"
          rel="noopener noreferrer"
        >
          この<?= $isProject ? '企画' : '投稿' ?>をXで共有
        </a>
      </p>
    </article>
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
