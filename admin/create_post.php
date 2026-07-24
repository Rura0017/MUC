<?php

declare(strict_types=1);

require_once __DIR__ . '/../include/auth.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/csrf.php';

requireAdmin();

$title = '';
$body = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRFトークンを確認し、外部サイトからの不正操作を防ぐ
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('不正なリクエストです。');
    }

    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');

    if ($title === '' || $body === '') {
        $error = 'タイトルと本文を入力してください。';

    } elseif (mb_strlen($title, 'UTF-8') > 100) {
        $error = 'タイトルは100文字以内にしてください。';

    } elseif (mb_strlen($body, 'UTF-8') > 10000) {
        $error = '本文は10000文字以内にしてください。';
    }

    // 入力エラーがなければSQLiteへ保存
    if ($error === '') {
        $pdo = db();

        $stmt = $pdo->prepare(
            '
            INSERT INTO posts (
                title,
                body
            )
            VALUES (
                :title,
                :body
            )
            '
        );

        $stmt->execute([
            ':title' => $title,
            ':body' => $body,
        ]);

        header('Location: index.php?created=1');
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">

  <link rel="stylesheet" href="../css/main.css?v=7">

  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
  >

  <title>新規投稿 | MUC</title>

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
      <h1>新規投稿</h1>
    </div>

    <div class="under-title">
      <nav class="header-content">
        <ul class="header-menu">
          <li>
            <a href="index.php">
              管理画面に戻る
            </a>
          </li>

          <li>
            <a href="../index.html">
              トップページ
            </a>
          </li>
        </ul>
      </nav>
    </div>

    <section class="post-create-area">
      <h2>投稿内容</h2>

      <div class="sentence">
        <?php if ($error !== ''): ?>
          <p class="login-error">
            <?= htmlspecialchars(
                $error,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
          </p>
        <?php endif; ?>

        <form
  class="admin-post-form"
  method="POST"
  action="create_post.php"
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

  <div class="admin-form-field">
    <label for="post-title">
      タイトル
    </label>

    <input
      id="post-title"
      name="title"
      type="text"
      maxlength="100"
      value="<?= htmlspecialchars(
          $title,
          ENT_QUOTES,
          'UTF-8'
      ) ?>"
      required
    >
  </div>

  <div class="admin-form-field">
    <label for="post-body">
      本文
    </label>

    <textarea
      id="post-body"
      name="body"
      rows="12"
      maxlength="10000"
      required
    ><?= htmlspecialchars(
        $body,
        ENT_QUOTES,
        'UTF-8'
    ) ?></textarea>
  </div>

  <p class="small">
    本文中にXの投稿URLを書くと、公開ページで自動的に埋め込み表示されます。
  </p>

  <button
    class="post-submit-button"
    type="submit"
  >
    投稿する
  </button>
</form>
      </div>
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

  <script>
    window.addEventListener("load", function () {
      document.body.classList.remove("loading");
      document.body.classList.add("loaded");
    });
  </script>
</body>
</html>