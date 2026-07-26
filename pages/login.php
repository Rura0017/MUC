<?php

declare(strict_types=1);

require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/auth.php';

startSession();

$error = '';

// すでにログイン済みなら管理画面へ移動
if (isset($_SESSION['admin_id'])) {
    header('Location: ../admin/index.php');
    exit;
}

// フォームが送信された場合
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'アカウント名とパスワードを入力してください。';
    } else {
        $pdo = db();

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

        if (
            $admin !== false
            && password_verify(
                $password,
                $admin['password_hash']
            )
        ) {
            // ログイン成功時にセッションIDを作り直す
            session_regenerate_id(true);

            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];

            header('Location: ../admin/index.php');
            exit;
        }

        $error = 'アカウント名またはパスワードが違います。';
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">

    <!-- CSS読み込み -->
    <link
        rel="stylesheet"
        href="../css/main.css"
    >

    <!-- スマホ対応 -->
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>管理者ログイン | MUC</title>

    <link
        rel="icon"
        href="../image/ogp.png"
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
</head>

<body class="loading">
    <div id="loading-screen">
        読み込み中...
    </div>

    <div class="site-wrapper">
        <div class="mbody">
            <h1>
                管理者<br>
                ログイン
            </h1>
        </div>

        <div class="under-title">
            <nav class="header-content">
                <ul class="header-menu">
                    <li>
                        <a href="../index.html">
                            トップに戻る
                        </a>
                    </li>
                </ul>
            </nav>
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
                    <div class="login-field">
                        <label for="signin-id">
                            アカウント名
                        </label>

                        <input
                            id="signin-id"
                            name="username"
                            type="text"
                            value="<?= htmlspecialchars(
                                $_POST['username'] ?? '',
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

    <script>
        window.addEventListener("load", function () {
            document.body.classList.remove("loading");
            document.body.classList.add("loaded");
        });
    </script>
</body>
</html>