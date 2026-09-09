<?php

declare(strict_types=1);

require_once __DIR__ . '/../include/auth.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/csrf.php';
require_once __DIR__ . '/../include/post_renderer.php';

requireAdmin();

$pdo = db();

$posts = $pdo
    ->query(
        '
        SELECT
            posts.id,
            posts.title,
            posts.body,
            posts.body_format,
            posts.post_type,
            posts.created_at,
            COUNT(post_attachments.id) AS attachment_count
        FROM posts
        LEFT JOIN post_attachments
            ON post_attachments.post_id = posts.id
        GROUP BY posts.id
        ORDER BY posts.id DESC
        '
    )
    ->fetchAll();

$created = isset($_GET['created']);
$deleted = isset($_GET['deleted']);
$passwordChangeResult = $_SESSION['password_change_result'] ?? null;
unset($_SESSION['password_change_result']);
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

  <title>管理画面 | MUC</title>

  <link
    rel="icon"
    href="../image/favicon.png"
    type="image/png"
  >
</head>

<body>
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
          <li><a href="index.php" aria-current="page">管理画面</a></li>
          <li>
            <a href="../index.html">
              トップページ
            </a>
          </li>

          <li>
            <a href="create_post.php">
              新規作成
            </a>
          </li>

          <li>
            <a
              href="../pages/act_menu.php"
              target="_blank"
              rel="noopener noreferrer"
            >
              活動内容を見る
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
        内容を追加しました。
      </p>
    <?php endif; ?>

    <?php if ($deleted): ?>
      <p class="admin-success">
        内容を削除しました。
      </p>
    <?php endif; ?>

    <details id="password-settings" class="password-settings" <?= $passwordChangeResult !== null ? 'open' : '' ?>>
      <summary>パスワードを変更</summary>
      <div class="password-settings-content">
        <?php if ($passwordChangeResult !== null): ?>
          <p class="<?= $passwordChangeResult['success'] ? 'admin-success' : 'login-error' ?>"
             role="<?= $passwordChangeResult['success'] ? 'status' : 'alert' ?>">
            <?= htmlspecialchars($passwordChangeResult['message'], ENT_QUOTES, 'UTF-8') ?>
          </p>
        <?php endif; ?>
        <p class="password-settings-help">現在のパスワードを確認してから、新しいパスワードに更新します。</p>
        <form class="login-form" method="POST" action="change_password.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="username" autocomplete="username"
                 value="<?= htmlspecialchars($_SESSION['admin_username'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="login-field">
            <label for="current-password">現在のパスワード</label>
            <input id="current-password" type="password" name="current_password" autocomplete="current-password" required>
          </div>
          <div class="login-field">
            <label for="new-password">新しいパスワード</label>
            <input id="new-password" type="password" name="new_password" autocomplete="new-password"
                   minlength="12" maxlength="72" aria-describedby="password-requirements" required>
            <p id="password-requirements" class="password-settings-help">12文字以上。上限は半角英数字で72文字、日本語は目安として24文字です。</p>
          </div>
          <div class="login-field">
            <label for="confirm-password">新しいパスワード（確認）</label>
            <input id="confirm-password" type="password" name="confirm_password" autocomplete="new-password"
                   minlength="12" maxlength="72" required>
          </div>
          <button class="password-change-button" type="submit">パスワードを更新</button>
        </form>
      </div>
    </details>

    <section class="post-management">
      <div class="post-management-title">
        <h2>投稿・企画管理</h2>

        <a
          class="admin-link-button"
          href="create_post.php"
        >
          ＋ 新規作成
        </a>
      </div>

      <?php if ($posts === []): ?>
        <div class="sentence">
          <p>まだ投稿・企画はありません。</p>
        </div>
      <?php endif; ?>

      <?php foreach ($posts as $post): ?>
        <article class="admin-post-card">
          <p class="content-type-badge content-type-<?=
              $post['post_type'] === 'activity'
                  ? 'activity'
                  : 'post'
          ?>">
            <?= $post['post_type'] === 'activity'
                ? '企画'
                : '通常投稿' ?>
          </p>

          <h3>
            <?= htmlspecialchars(
                $post['title'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>
          </h3>

          <p class="admin-post-date">
            公開日：
            <?= htmlspecialchars(
                $post['created_at'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>
          </p>

          <div class="admin-post-body">
            <?= nl2br(
                htmlspecialchars(
                    postBodyPlainText(
                        $post['body'],
                        $post['body_format']
                    ),
                    ENT_QUOTES,
                    'UTF-8'
                )
            ) ?>
          </div>

          <?php if ((int) $post['attachment_count'] > 0): ?>
            <p class="admin-post-attachments">
              画像・動画の添付: <?= (int) $post['attachment_count'] ?>件
            </p>
          <?php endif; ?>

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
            onsubmit="return confirm('この内容を削除しますか？');"
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
              削除する
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
</body>
</html>
