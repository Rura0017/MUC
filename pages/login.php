<?php

declare(strict_types=1);

require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/auth.php';
require_once __DIR__ . '/../include/csrf.php';
require_once __DIR__ . '/../include/login_security.php';

startSession();

$error = '';
$username = '';

// すでにログイン済みなら管理画面へ移動
if (isAdminSessionValid()) {
    header('Location: ../admin/index.php');
    exit;
}

// フォームが送信された場合
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('不正なリクエストです。ページを開き直してください。');
    }
    $username = trim(postString('username'));
    $password = postString('password');

    if ($username === '' || $password === '' || strlen($username) > 255 || strlen($password) > 4096 || str_contains($password, "\0")) {
        $error = 'アカウント名とパスワードを入力してください。';
    } else {
        $pdo = db();
        // Forwardedヘッダーはクライアントが偽造できるので利用しない。
        $retryAfter = reserveLoginAttempt($pdo, $username, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($retryAfter > 0) {
            http_response_code(429);
            header('Retry-After: ' . $retryAfter);
            $error = 'ログインの試行回数が上限に達しました。しばらく待ってから再度お試しください。';
        } else {

            $stmt = $pdo->prepare(
                '
                SELECT
                    id,
                    username,
                    password_hash
                FROM admins
                WHERE username = :username
                '
            );

            $stmt->execute([
                ':username' => $username,
            ]);

            $admin = $stmt->fetch();

            // 存在しないユーザーでもハッシュ照合を行い、応答時間の差を小さくする。
            $hash = $admin['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $passwordMatches = password_verify($password, $hash);
            if ($admin !== false && $passwordMatches) {
                clearLoginAccountAttempts($pdo, $username);
                // ログイン成功時にセッションIDを作り直す
                session_regenerate_id(true);
                $_SESSION = [];

                $_SESSION['admin_id'] = (int) $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_authenticated_at'] = time();
                $_SESSION['admin_last_activity'] = time();
                $_SESSION['admin_password_version'] = hash('sha256', $admin['password_hash']);

                header('Location: ../admin/index.php');
                exit;
            }

            $error = 'アカウント名またはパスワードが違います。';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;600;700&amp;display=swap">

    <!-- CSS読み込み -->
    <link
        rel="stylesheet"
        href="../css/main.css?v=20260909-10"
    >

    <!-- スマホ対応 -->
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>管理者ログイン | MUC</title>

    <link
        rel="icon"
        href="../image/favicon.png"
        type="image/png"
    >

    <!-- OGP設定 -->
    <meta
        property="og:title"
        content="MUC 管理者ログイン"
    >
    <meta
        property="og:description"
        content="MUC管理者用ログインページ"
    >
    <meta
        property="og:type"
        content="website"
    >
    <meta
        property="og:site_name"
        content="MUC"
    >
    <meta
        property="og:url"
        content="https://ousmuc.motti-web.com/pages/login.php"
    >
    <meta
        property="og:image"
        content="https://ousmuc.motti-web.com/image/ogp.png"
    >

    <!-- X / Twitterカード設定 -->
    <meta
        name="twitter:card"
        content="summary_large_image"
    >
    <meta
        name="twitter:title"
        content="MUC 管理者ログイン"
    >
    <meta
        name="twitter:description"
        content="MUC管理者用ログインページ"
    >
    <meta
        name="twitter:image"
        content="https://ousmuc.motti-web.com/image/ogp.png"
    >
  <script src="../scripts/navigation.js?v=20260910-1" defer></script>
</head>

<body>
    <div class="site-wrapper">
    <header class="site-header">
      <div class="mbody">
            <h1>
                管理者<br>
                ログイン
            </h1>
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
            <li><a href="posts.php">投稿一覧</a></li>
            <li><a href="login.php" aria-current="page">ログイン</a></li>
          </ul>
        </nav>
      </details>
    </header>
    <div class="page-home-return">
      <a class="home-return-link" href="../index.html">
        <span aria-hidden="true">←</span> ホームへ戻る
      </a>
    </div>




        <div class="login-area">
            <h2>管理者ログイン</h2>

            <div class="sentence">
                <p class="normal">
                    管理者アカウントの情報を入力してください。
                </p>

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
                    class="login-form"
                    method="POST"
                    action="login.php"
                >
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="login-field">
                        <label for="signin-id">
                            アカウント名
                        </label>

                        <input
                            id="signin-id"
                            name="username"
                            type="text"
                            value="<?= htmlspecialchars(
                                $username,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            autocomplete="username"
                            required
                        >
                    </div>

                    <div class="login-field">
                        <label for="signin-pass">
                            パスワード
                        </label>

                        <input
                            id="signin-pass"
                            name="password"
                            type="password"
                            autocomplete="current-password"
                            required
                        >
                    </div>

                    <button
                        class="login-button"
                        type="submit"
                    >
                        ログインする
                    </button>
                </form>
            </div>
        </div>
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
