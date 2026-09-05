<?php

declare(strict_types=1);

/**
 * SQLiteデータベースへ接続する関数
 */
function db(): PDO
{
    // 同じPHP処理内では、作成済みの接続を再利用する
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // includeフォルダの1つ上にあるstorageを指定
    $databasePath = dirname(__DIR__) . '/storage/muc.sqlite';

    // SQLiteへ接続する
    $pdo = new PDO('sqlite:' . $databasePath);

    // SQLで問題が起きた場合、例外として通知する
    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    // 検索結果を「列名 => 値」の配列で取得する
    $pdo->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );

    // 外部キー制約を有効にする
    $pdo->exec('PRAGMA foreign_keys = ON');

    // 管理者アカウント用テーブル
    $pdo->exec(
        '
        CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
        '
    );

    // 投稿用テーブル
    // 新しくDBを作る場合は、最初からx_post_idも作られる
    $pdo->exec(
        '
        CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            x_post_id TEXT,
            post_type TEXT NOT NULL DEFAULT \'post\',
            body_format TEXT NOT NULL DEFAULT \'plain\',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT
        )
        '
    );

    /*
     * 既存のpostsテーブルにx_post_idがない場合だけ追加する。
     *
     * CREATE TABLE IF NOT EXISTSは、すでにテーブルがある場合、
     * 列構成を更新してくれないため、この処理が必要。
     */
    $columns = $pdo
        ->query('PRAGMA table_info(posts)')
        ->fetchAll();

    $columnNames = array_column($columns, 'name');

    if (!in_array('x_post_id', $columnNames, true)) {
        $pdo->exec(
            'ALTER TABLE posts ADD COLUMN x_post_id TEXT'
        );
    }

    /*
     * 企画管理機能より前に作られた投稿は、通常投稿として維持する。
     * 既存列や既存レコードを書き換えず、区分列だけを追加する。
     */
    if (!in_array('post_type', $columnNames, true)) {
        $pdo->exec(
            "ALTER TABLE posts ADD COLUMN post_type TEXT NOT NULL DEFAULT 'post'"
        );
    }

    /*
     * 従来本文はプレーンテキストのまま維持する。
     * 新しいリッチ本文だけをquill形式として保存する。
     */
    if (!in_array('body_format', $columnNames, true)) {
        $pdo->exec(
            "ALTER TABLE posts ADD COLUMN body_format TEXT NOT NULL DEFAULT 'plain'"
        );
    }

    $pdo->exec(
        '
        CREATE INDEX IF NOT EXISTS idx_posts_post_type
        ON posts(post_type, id DESC)
        '
    );

    // 一度だけ行うデータ移行を記録する
    $pdo->exec(
        '
        CREATE TABLE IF NOT EXISTS app_migrations (
            name TEXT PRIMARY KEY,
            applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
        '
    );

    /*
     * これまでHTMLへ直接記述していた4企画をDBへ移す。
     * 移行記録を先に確保するため、管理画面から削除した後に再作成されない。
     */
    $pdo->beginTransaction();

    try {
        $migration = $pdo->prepare(
            '
            INSERT OR IGNORE INTO app_migrations (name)
            VALUES (:name)
            '
        );
        $migration->execute([
            ':name' => 'seed_initial_activity_projects_v1',
        ]);

        if ($migration->rowCount() === 1) {
            $insertProject = $pdo->prepare(
                '
                INSERT INTO posts (
                    title,
                    body,
                    post_type,
                    created_at
                )
                VALUES (
                    :title,
                    :body,
                    \'activity\',
                    :created_at
                )
                '
            );

            // 画面ではIDの降順で表示するため、従来と逆順で登録する
            $initialProjects = [
                [
                    'title' => 'その他の思いつき企画',
                    'body' => "状態：随時追加\n概要：突拍子もないひらめきや雑談から生まれた企画を、実行できそうなら形にしようという枠です。\n方針：形にできそうならやってみる。\nURL：ページ整備中",
                    'created_at' => '2026-04-01 00:00:00',
                ],
                [
                    'title' => '学内調査系企画',
                    'body' => "状態：進行中\n概要：学内の設備や場所に関する情報を集め、便利に使える形で整理する企画です。\n例：ゴミ箱の位置調査、学内ゴミ箱のデータベース化。\n今後：データ収集を引き続き行い、HP上での公開を進めます。\nURL：ページ整備中",
                    'created_at' => '2026-04-01 00:00:00',
                ],
                [
                    'title' => '公式HP制作・運用',
                    'body' => "状態：進行中\n概要：MUCのアレコレを公開するための公式HPを制作・運用しています。\nやったこと：ページの分岐化、UI・UXの改善、ドメインの取得、サーバー移行、ブラウザ掲載\n今後：それぞれの活動ページの整備を進める予定です。\nURL：ページ整備中",
                    'created_at' => '2026-03-15 00:00:00',
                ],
                [
                    'title' => '年中行事をやってみようの会',
                    'body' => "状態：進行中\n開始：2026年4月\n概要：季節の行事や記念日から着想を得て、その日にちなんだ活動を行います。\nやったこと：お花見\n今後：アイデアが出次第、随時活動を行う予定です。\nURL：ページ整備中",
                    'created_at' => '2026-04-01 00:00:00',
                ],
            ];

            foreach ($initialProjects as $project) {
                $insertProject->execute([
                    ':title' => $project['title'],
                    ':body' => $project['body'],
                    ':created_at' => $project['created_at'],
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }

    // 投稿に紐づく画像・動画の情報を保持する
    $pdo->exec(
        '
        CREATE TABLE IF NOT EXISTS post_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            stored_name TEXT NOT NULL UNIQUE,
            original_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            media_kind TEXT NOT NULL,
            placement TEXT NOT NULL DEFAULT \'gallery\',
            display_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (post_id)
                REFERENCES posts(id)
                ON DELETE CASCADE
        )
        '
    );

    $attachmentColumns = $pdo
        ->query('PRAGMA table_info(post_attachments)')
        ->fetchAll();

    $attachmentColumnNames = array_column(
        $attachmentColumns,
        'name'
    );

    if (!in_array('placement', $attachmentColumnNames, true)) {
        $pdo->exec(
            "ALTER TABLE post_attachments ADD COLUMN placement TEXT NOT NULL DEFAULT 'gallery'"
        );
    }

    $pdo->exec(
        '
        CREATE INDEX IF NOT EXISTS idx_post_attachments_post_id
        ON post_attachments(post_id, display_order, id)
        '
    );

    return $pdo;
}
