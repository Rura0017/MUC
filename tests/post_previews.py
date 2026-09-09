"""Check homepage post previews using an isolated temporary database.

Run: python tests/post_previews.py (PHP with pdo_sqlite and mbstring required).
"""
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile


ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')


def run():
    handle, path = tempfile.mkstemp(prefix='muc-post-previews-', suffix='.sqlite')
    os.close(handle)
    database = Path(path)
    env = dict(os.environ, MUC_DATABASE_PATH=str(database))

    def request(previews=True):
        code = ("$_GET['previews'] = '1'; " if previews else '') + 'require $argv[1];'
        result = subprocess.run(
            [PHP, '-r', code, str(ROOT / 'api/latest_post.php')],
            env=env, check=True, capture_output=True, encoding='utf-8'
        )
        assert not result.stderr, result.stderr
        return json.loads(result.stdout)

    try:
        assert request()['previews'] == []
        assert request(False)['post'] is None

        def insert(title, body, body_format='plain', post_type='post'):
            with sqlite3.connect(database) as connection:
                result = connection.execute(
                    'INSERT INTO posts (title, body, body_format, post_type, created_at) '
                    'VALUES (?, ?, ?, ?, ?)',
                    (title, body, body_format, post_type, '2026-09-08 16:30:00')
                )
                return result.lastrowid

        plain_id = insert('最初の投稿', '  学内を\n\n 散歩しました。  ')
        first = request()
        assert len(first['previews']) == 1
        assert first['previews'][0]['excerpt'] == '学内を 散歩しました。'
        assert first['previews'][0]['created_at'] == '2026/09/09 01:30'
        assert first['previews'][0]['created_at_iso'] == '2026-09-09T01:30:00+09:00'
        assert 'post' not in first

        rich_id = insert('書式つき投稿', json.dumps({'ops': [
            {'insert': '冒頭の文章', 'attributes': {'bold': True}},
            {'insert': '\n本文の続き。\n'}
        ]}), 'quill')
        special_title = '<img src=x onerror=alert(1)> タイトル'
        newest_id = insert(special_title, '水と🌱' * 100)
        insert('企画は最近の投稿に混ぜない', '企画の本文', post_type='activity')
        cards = request()['previews']
        assert [post['id'] for post in cards] == [newest_id, rich_id, plain_id]
        assert cards[0]['title'] == special_title
        assert len(cards[0]['excerpt']) == 240
        assert cards[0]['excerpt'] == ('水と🌱' * 100)[:240]
        assert cards[1]['excerpt'] == '冒頭の文章 本文の続き。'
        assert all('body_html' not in post for post in cards)

        # Keep the existing full-post response available for other callers.
        full = request(False)['post']
        assert full['id'] == newest_id
        assert full['title'] == special_title
        assert 'body_html' in full and 'attachments_html' in full

        media_id = insert('写真のみ', json.dumps({'ops': [
            {'insert': {'image': '/image/uploads/example.png'}}, {'insert': '\n'}
        ]}), 'quill')
        cards = request()['previews']
        assert len(cards) == 3
        assert cards[0]['id'] == media_id and cards[0]['excerpt'] == ''
        assert plain_id not in [post['id'] for post in cards]

        env['MUC_DATABASE_PATH'] = str(database) + '.missing'
        failed = request()
        assert 'error' in failed and 'previews' not in failed
        print('Post previews: empty, single, latest 3, date, rich text, Unicode, media, legacy response and failure checks passed.')
    finally:
        database.unlink(missing_ok=True)


if __name__ == '__main__':
    run()
