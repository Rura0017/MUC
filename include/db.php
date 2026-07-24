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

    return $pdo;
}