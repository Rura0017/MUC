<?php

declare(strict_types=1);

require_once __DIR__ . '/../include/auth.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/csrf.php';

requireAdmin();

$pdo = db();

$posts = $pdo
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

$created = isset($_GET['created']);
$deleted = isset($_GET['deleted']);
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

  <title>管理画面 | MUC</title>

  <link
    rel="icon"
    href="../image/ogp.png"
    type="image/png"
  >
</head>

<body class="loading">
  <div id="loading-screen">
    読み込み中...
  </div>

  <div class="site-wrapper">
    <div class="mbody">
      <h1>
        MUC<br>
        管理画面
      </h1>
    </div>

    <div class="under-title">
      <nav class="header-content">
        <ul class="header-menu">
          <li>
            <a href="../index.html">
              トップページ
            </a>
          </li>

          <li>
            <a href="create_post.php">
              新規投稿
            </a>
          </li>
        </ul>
      </nav>

      <div class="member-row">
        <b>ログイン中：</b>

        <span class="admin">
          <?= htmlspecialchars(
              $_SESSION['admin_username'],
              ENT_QUOTES,
              'UTF-8'
          ) ?>
        </span>
      </div>
    </div>

    <?php if ($created): ?>
      <p class="admin-success">
        投稿を追加しました。
      </p>
    <?php endif; ?>

    <?php if ($deleted): ?>
      <p class="admin-success">
        投稿を削除しました。
      </p>
    <?php endif; ?>

    <section class="post-management">
      <div class="post-management-title">
        <h2>投稿管理</h2>

        <a
          class="admin-link-button"
          href="create_post.php"
        >
          ＋ 新規投稿
        </a>
      </div>

      <?php if ($posts === []): ?>
        <div class="sentence">
          <p>まだ投稿はありません。</p>
        </div>
      <?php endif; ?>

      <?php foreach ($posts as $post): ?>
        <article class="admin-post-card">
          <h3>
            <?= htmlspecialchars(
                $post['title'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>
          </h3>

          <p class="admin-post-date">
            投稿日：
            <?= htmlspecialchars(
                $post['created_at'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>
          </p>

          <div class="admin-post-body">
            <?= nl2br(
                htmlspecialchars(
                    $post['body'],
                    ENT_QUOTES,
                    'UTF-8'
                )
            ) ?>
          </div>
            <p class="admin-post-public-link">
                <a
                    class="admin-link-button"
                    href="../pages/post.php?id=<?= (int) $post['id'] ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    公開ページを確認
                </a>
            </p>
          <form
            class="delete-post-form"
            method="POST"
            action="delete_post.php"
            onsubmit="return confirm('この投稿を削除しますか？');"
          >
            <input
              type="hidden"
              name="csrf_token"
              value="<?= htmlspecialchars(
                  csrfToken(),
                  ENT_QUOTES,
                  'UTF-8'
              ) ?>"
            >

            <input
              type="hidden"
              name="post_id"
              value="<?= (int) $post['id'] ?>"
            >

            <button
              class="delete-button"
              type="submit"
            >
              投稿を削除
            </button>
          </form>
        </article>
      <?php endforeach; ?>
    </section>

    <div class="admin-logout-area">
      <form
        method="POST"
        action="../logout.php"
      >
        <input
          type="hidden"
          name="csrf_token"
          value="<?= htmlspecialchars(
              csrfToken(),
              ENT_QUOTES,
              'UTF-8'
          ) ?>"
        >

        <button
          class="logout-button"
          type="submit"
        >
          ログアウト
        </button>
      </form>
    </div>
  </div>
  <script>
    window.addEventListener("load", function () {
      document.body.classList.remove("loading");
      document.body.classList.add("loaded");
    });
  </script>
</body>
</html>