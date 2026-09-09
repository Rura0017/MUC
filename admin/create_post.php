<?php

declare(strict_types=1);

require_once __DIR__ . '/../include/auth.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/csrf.php';
require_once __DIR__ . '/../include/post_attachments.php';
require_once __DIR__ . '/../include/post_renderer.php';

requireAdmin();

$title = '';
$body = '';
$bodyFormat = POST_BODY_FORMAT_PLAIN;
$postType = 'post';
$error = '';
$inlineImages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRFトークンを確認し、外部サイトからの不正操作を防ぐ
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('不正なリクエストです。');
    }

    $title = trim(postString('title'));
    $body = trim(postString('body'));
    $bodyFormat = postString('body_format', POST_BODY_FORMAT_PLAIN);
    $postType = postString('post_type', 'post');

    if (!in_array(
        $bodyFormat,
        [POST_BODY_FORMAT_PLAIN, POST_BODY_FORMAT_QUILL],
        true
    )) {
        $error = '本文の形式を確認できませんでした。';

    } elseif (!in_array($postType, ['post', 'activity'], true)) {
        $error = '投稿の種類を選択してください。';

    } elseif ($title === '') {
        $error = 'タイトルを入力してください。';

    } elseif (mb_strlen($title, 'UTF-8') > 100) {
        $error = 'タイトルは100文字以内にしてください。';

    }

    if ($error === '') {
        try {
            $inlineImages = collectPostAttachments(
                $_FILES['inline_images'] ?? null,
                ['image']
            );

            validatePostAttachmentTotals($inlineImages);

            if ($bodyFormat === POST_BODY_FORMAT_QUILL) {
                $inlineImageLookup = [];

                foreach ($inlineImages as $inlineImage) {
                    $inlineImageLookup[$inlineImage['upload_key']]
                        = $inlineImage;
                }

                $body = normalizeQuillBody(
                    $body,
                    array_keys($inlineImageLookup)
                );

                $inlineImageTokens = quillInlineImageTokens($body);
                $usedInlineImages = [];

                foreach ($inlineImageTokens as $inlineImageToken) {
                    if (!isset($inlineImageLookup[$inlineImageToken])) {
                        throw new RuntimeException(
                            '本文内の画像をもう一度選択してください。'
                        );
                    }

                    $usedInlineImages[] =
                        $inlineImageLookup[$inlineImageToken];
                }

                $inlineImages = $usedInlineImages;
            } else {
                if (mb_strlen($body, 'UTF-8') > POST_BODY_MAX_TEXT_LENGTH) {
                    throw new RuntimeException(
                        '本文は10000文字以内にしてください。'
                    );
                }

                if ($inlineImages !== []) {
                    throw new RuntimeException(
                        '本文内画像を利用するにはリッチエディタを有効にしてください。'
                    );
                }
            }
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }
    }

    if (
        $error === ''
        && !postBodyHasContent($body, $bodyFormat)
    ) {
        $error = '本文または画像を1件以上追加してください。';
    }

    // 入力エラーがなければSQLiteへ保存
    if ($error === '') {
        try {
            $pdo = db();
            $savedAttachmentFiles = [];
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                '
                INSERT INTO posts (
                    title,
                    body,
                    body_format,
                    post_type
                )
                VALUES (
                    :title,
                    :body,
                    :body_format,
                    :post_type
                )
                '
            );

            $stmt->execute([
                ':title' => $title,
                ':body' => $body,
                ':body_format' => $bodyFormat,
                ':post_type' => $postType,
            ]);

            $postId = (int) $pdo->lastInsertId();

            $savedInlineFiles = savePostAttachments(
                $pdo,
                $postId,
                $inlineImages,
                'inline'
            );

            $savedAttachmentFiles = $savedInlineFiles;

            if ($bodyFormat === POST_BODY_FORMAT_QUILL) {
                $inlineImageUrls = [];

                foreach ($savedInlineFiles as $savedInlineFile) {
                    $inlineImageUrls[$savedInlineFile['upload_key']]
                        = postAttachmentRootUrl(
                            $savedInlineFile['stored_name']
                        );
                }

                $body = replaceQuillImageUploads(
                    $body,
                    $inlineImageUrls
                );

                $updateBody = $pdo->prepare(
                    '
                    UPDATE posts
                    SET body = :body
                    WHERE id = :id
                    '
                );
                $updateBody->execute([
                    ':body' => $body,
                    ':id' => $postId,
                ]);
            }

            $pdo->commit();

            header('Location: index.php?created=1');
            exit;
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            foreach ($savedAttachmentFiles ?? [] as $savedAttachmentFile) {
                if (is_file($savedAttachmentFile['path'])) {
                    unlink($savedAttachmentFile['path']);
                }
            }

            $error =
                '内容を保存できませんでした。時間をおいてもう一度お試しください。';
        }
    }
}

$fallbackBody = postBodyPlainText($body, $bodyFormat);
?>

<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;600;700&amp;display=swap">

  <link rel="stylesheet" href="../css/main.css?v=20260909-10">

  <link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css"
  >

  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
  >

  <title>投稿・企画の新規作成 | MUC</title>

  <link
    rel="icon"
    href="../image/favicon.png"
    type="image/png"
  >
</head>

<body>
  <div class="site-wrapper">
    <div class="mbody">
      <h1>新規作成</h1>
    </div>

    <div class="under-title">
      <nav class="header-content">
        <ul class="header-menu">
          <li><a href="create_post.php" aria-current="page">新規作成</a></li>
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
      <h2>投稿・企画を作成</h2>

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
  enctype="multipart/form-data"
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

  <fieldset class="admin-form-field post-type-field">
    <legend>投稿の種類</legend>

    <div class="post-type-options">
      <label class="post-type-option">
        <input
          name="post_type"
          type="radio"
          value="post"
          <?= $postType === 'post' ? 'checked' : '' ?>
        >

        <span>
          <b>通常投稿</b>
          <small>トップ・投稿一覧に掲載</small>
        </span>
      </label>

      <label class="post-type-option">
        <input
          name="post_type"
          type="radio"
          value="activity"
          <?= $postType === 'activity' ? 'checked' : '' ?>
        >

        <span>
          <b>企画</b>
          <small>活動内容ページの企画一覧に掲載</small>
        </span>
      </label>
    </div>
  </fieldset>

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

    <div
      id="post-rich-editor"
      class="post-rich-editor"
      hidden
    ></div>

    <textarea
      id="post-body"
      name="body"
      rows="12"
    ><?= htmlspecialchars(
        $fallbackBody,
        ENT_QUOTES,
        'UTF-8'
    ) ?></textarea>

    <input
      id="post-body-format"
      name="body_format"
      type="hidden"
      value="plain"
    >

    <div id="inline-image-inputs"></div>

    <p class="upload-help rich-editor-help">
      ツールバーから文字装飾や色を選べます。画像ボタンを押すと、現在のカーソル位置へ画像を挿入できます。
    </p>
  </div>

  <p class="small">
    Xの投稿URLを1行だけで書くと、公開ページで自動的に埋め込み表示されます。
  </p>

  <p class="small">
    企画では、本文を「状態：」「概要：」「今後：」のように改行して書くと読みやすくなります。
  </p>

  <button
    class="post-submit-button"
    type="submit"
  >
    公開する
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

  <script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>

  <script>
    (function () {
      const richEditorElement = document.getElementById(
        "post-rich-editor"
      );
      const bodyField = document.getElementById("post-body");
      const bodyFormatField = document.getElementById(
        "post-body-format"
      );
      const form = document.querySelector(".admin-post-form");
      const inlineInputArea = document.getElementById(
        "inline-image-inputs"
      );
      const editorHelp = document.querySelector(".rich-editor-help");

      if (
        !window.Quill
        || !richEditorElement
        || !bodyField
        || !bodyFormatField
        || !form
        || !inlineInputArea
      ) {
        return;
      }

      richEditorElement.hidden = false;

      const quill = new Quill(richEditorElement, {
        theme: "snow",
        placeholder: "本文を入力してください…",
        formats: [
          "header",
          "bold",
          "italic",
          "underline",
          "strike",
          "color",
          "background",
          "size",
          "list",
          "blockquote",
          "align",
          "indent",
          "link",
          "image"
        ],
        modules: {
          toolbar: [
            [{ header: [2, 3, false] }],
            ["bold", "italic", "underline", "strike"],
            [{ color: [] }, { background: [] }],
            [{ size: ["small", false, "large", "huge"] }],
            [{ list: "ordered" }, { list: "bullet" }],
            [{ align: [] }],
            ["blockquote", "link", "image"],
            ["clean"]
          ]
        }
      });

      const initialBody = <?= json_encode(
          $body,
          JSON_HEX_TAG
          | JSON_HEX_AMP
          | JSON_HEX_APOS
          | JSON_HEX_QUOT
          | JSON_THROW_ON_ERROR
      ) ?>;
      const initialBodyFormat = <?= json_encode(
          $bodyFormat,
          JSON_HEX_TAG
          | JSON_HEX_AMP
          | JSON_HEX_APOS
          | JSON_HEX_QUOT
          | JSON_THROW_ON_ERROR
      ) ?>;

      if (initialBodyFormat === "quill") {
        try {
          const initialDocument = JSON.parse(initialBody);

          // 再送時はブラウザがファイルを保持できないため、
          // 未保存の画像だけを外して本文と装飾を復元する。
          initialDocument.ops = (initialDocument.ops || []).filter(
            function (operation) {
              const source = operation.insert?.image;
              return !(
                typeof source === "string"
                && source.startsWith("upload:")
              );
            }
          );

          quill.setContents(initialDocument);
        } catch (error) {
          quill.setText(bodyField.value);
        }
      } else {
        quill.setText(initialBody);
      }

      bodyField.classList.add("rich-editor-fallback-hidden");
      bodyFormatField.value = "quill";

      if (editorHelp) {
        editorHelp.classList.add("is-active");
      }

      const previewImages = new Map();
      let imageCounter = 0;

      function imageCount() {
        return quill.getContents().ops.reduce(
          function (count, operation) {
            return count + (operation.insert?.image ? 1 : 0);
          },
          0
        );
      }

      let isComposing = false;

      function syncEditorPlaceholder() {
        // IMEの未確定文字はQuillの本文データにまだ反映されないため、
        // 入力中のDOMを使って案内文の表示だけを更新する。
        const isEmpty =
          !isComposing
          && quill.root.textContent.trim() === ""
          && !quill.root.querySelector("img");

        quill.root.classList.toggle("ql-blank", isEmpty);
      }

      quill.root.addEventListener("compositionstart", function () {
        isComposing = true;
        syncEditorPlaceholder();
      });
      quill.root.addEventListener("compositionend", function () {
        isComposing = false;
        // 変換確定・取り消し後のDOMとQuillの更新を待って再判定する。
        queueMicrotask(syncEditorPlaceholder);
      });
      quill.root.addEventListener("input", syncEditorPlaceholder);
      quill.on("text-change", syncEditorPlaceholder);
      syncEditorPlaceholder();

      function chooseInlineImage() {
        if (imageCount() >= 8) {
          window.alert(
            "本文内画像は8件までです。"
          );
          return;
        }

        const token = "inline_"
          + Date.now().toString(36)
          + "_"
          + imageCounter.toString(36);
        imageCounter += 1;

        const input = document.createElement("input");
        input.type = "file";
        input.name = "inline_images[" + token + "]";
        input.className = "inline-image-file-input";
        input.accept = "image/jpeg,image/png,image/gif,image/webp";
        input.hidden = true;
        inlineInputArea.appendChild(input);

        input.addEventListener("change", function () {
          const file = input.files?.[0];

          if (!file) {
            input.remove();
            return;
          }

          const allowedTypes = [
            "image/jpeg",
            "image/png",
            "image/gif",
            "image/webp"
          ];

          if (!allowedTypes.includes(file.type)) {
            window.alert(
              "本文内にはJPEG・PNG・GIF・WebP画像を挿入できます。"
            );
            input.remove();
            return;
          }

          if (file.size < 1 || file.size > 26214400) {
            window.alert("画像は1件25MB以下にしてください。");
            input.remove();
            return;
          }

          const range = quill.getSelection(true) || {
            index: quill.getLength(),
            length: 0
          };
          const reader = new FileReader();

          reader.addEventListener("load", function () {
            if (typeof reader.result !== "string") {
              input.remove();
              return;
            }

            previewImages.set(reader.result, { token, input, file });
            quill.insertEmbed(
              range.index,
              "image",
              reader.result,
              "user"
            );
            quill.insertText(range.index + 1, "\n", "user");
            quill.setSelection(range.index + 2, 0, "silent");
          });
          reader.readAsDataURL(file);
        }, { once: true });

        input.click();
      }

      quill.getModule("toolbar").addHandler(
        "image",
        chooseInlineImage
      );

      const toolbarLabels = {
        ".ql-bold": "太字",
        ".ql-italic": "斜体",
        ".ql-underline": "下線",
        ".ql-strike": "取り消し線",
        ".ql-blockquote": "引用",
        ".ql-link": "リンク",
        ".ql-image": "画像を途中に挿入",
        ".ql-clean": "装飾を解除"
      };

      Object.entries(toolbarLabels).forEach(function (entry) {
        const button = document.querySelector(entry[0]);

        if (button) {
          button.title = entry[1];
          button.setAttribute("aria-label", entry[1]);
        }
      });

      form.addEventListener("submit", function (event) {
        const documentToSave = JSON.parse(
          JSON.stringify(quill.getContents())
        );
        const usedTokens = new Set();
        let unsupportedImage = false;

        documentToSave.ops.forEach(function (operation) {
          const source = operation.insert?.image;

          if (typeof source !== "string") {
            return;
          }

          const preview = previewImages.get(source);

          if (!preview) {
            unsupportedImage = true;
            return;
          }

          usedTokens.add(preview.token);
          operation.insert.image = "upload:" + preview.token;
        });

        if (unsupportedImage) {
          event.preventDefault();
          window.alert(
            "本文内画像は、ツールバーの画像ボタンから選択してください。"
          );
          return;
        }

        let inlineBytes = 0;

        previewImages.forEach(function (preview) {
          preview.input.disabled = !usedTokens.has(preview.token);

          if (usedTokens.has(preview.token)) {
            inlineBytes += preview.file.size;
          }
        });

        if (usedTokens.size > 8 || inlineBytes > 104857600) {
          event.preventDefault();
          window.alert(
            "本文内画像は8件、合計100MBまでです。"
          );
          return;
        }

        if (quill.getText().trim() === "" && usedTokens.size === 0) {
          event.preventDefault();
          window.alert("本文または画像を1件以上追加してください。");
          return;
        }

        bodyField.value = JSON.stringify(documentToSave);
        bodyFormatField.value = "quill";
      });
    }());

  </script>
</body>
</html>
