"""Check sitemap responses and database preservation using isolated fixtures.

Run: python tests/sitemap.py (PHP with pdo_sqlite required).
"""
from contextlib import closing
import hashlib
import os
from pathlib import Path
import socket
import sqlite3
import stat
import subprocess
import tempfile
import time
from urllib.request import urlopen
import xml.etree.ElementTree as ET


ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')
ORIGIN = 'https://ousmuc.motti-web.com'
NAMESPACE = {'s': 'http://www.sitemaps.org/schemas/sitemap/0.9'}
STATIC_PATHS = [
    '/', '/pages/act_menu.php', '/pages/purpose.html',
    '/pages/regulations.html', '/pages/join.html', '/pages/posts.php',
]


def database_state(database):
    """Capture file bytes and schema without creating or changing a database."""
    files = {}
    for path in database.parent.glob(database.name + '*'):
        if path.is_file():
            files[path.name] = hashlib.sha256(path.read_bytes()).hexdigest()
    tables = None
    if database.is_file():
        try:
            with closing(sqlite3.connect(database.as_uri() + '?mode=ro')) as connection:
                tables = connection.execute(
                    'SELECT name, sql FROM sqlite_master ORDER BY name'
                ).fetchall()
        except sqlite3.DatabaseError:
            pass
    return files, tables


def create_database(database, updated_at=True):
    """Build a minimal posts table; production migration tables are unnecessary."""
    database.unlink(missing_ok=True)
    with closing(sqlite3.connect(database)) as connection:
        connection.execute(
            'CREATE TABLE posts (id INTEGER PRIMARY KEY, created_at TEXT'
            + (', updated_at TEXT' if updated_at else '') + ')'
        )
        if updated_at:
            connection.executemany('INSERT INTO posts VALUES (?, ?, ?)', [
                (1, '2026-09-08 16:30:00', '2026-09-09 10:00:00'),
                (2, '2026-09-08 16:30:00', ''),
                (3, '', None),
                (4, 'not-a-date', ''),
                (5, '2026-09-08 16:30:00', '   '),
            ])
        else:
            connection.executemany('INSERT INTO posts VALUES (?, ?)', [
                (1, '2026-09-08 16:30:00'),
                (2, ''),
            ])
        connection.commit()


def run():
    with tempfile.TemporaryDirectory(prefix='muc-sitemap-') as directory:
        directory = Path(directory)
        database = directory / 'fixture.sqlite'
        log_path = directory / 'server.log'
        create_database(database)
        initial_state = database_state(database)
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        url = f'http://127.0.0.1:{port}/sitemap.php'
        env = dict(os.environ, MUC_DATABASE_PATH=str(database))
        with log_path.open('w', encoding='utf-8') as server_log:
            server = subprocess.Popen(
                [PHP, '-S', f'127.0.0.1:{port}', '-t', str(ROOT)],
                env=env, stdout=server_log, stderr=server_log,
                creationflags=0x08000000 if os.name == 'nt' else 0,
            )
            try:
                deadline = time.monotonic() + 10
                while True:
                    try:
                        with urlopen(url, timeout=2) as response:
                            response.read()
                        break
                    except OSError:
                        if server.poll() is not None or time.monotonic() >= deadline:
                            raise RuntimeError('Local PHP server did not start.')
                        time.sleep(0.05)
                assert database_state(database) == initial_state

                def request(ids, dates, expect_error=False):
                    previous_log = log_path.read_text(encoding='utf-8')
                    with urlopen(url, timeout=10) as response:
                        assert response.status == 200
                        assert response.headers.get_content_type() == 'application/xml'
                        assert response.headers.get_content_charset() == 'utf-8'
                        assert response.headers['X-Content-Type-Options'] == 'nosniff'
                        body = response.read()
                    assert body.startswith(b'<?xml version="1.0" encoding="UTF-8"?>')
                    document = ET.fromstring(body)
                    assert document.tag == '{' + NAMESPACE['s'] + '}urlset'
                    entries = document.findall('s:url', NAMESPACE)
                    locations = [entry.findtext('s:loc', namespaces=NAMESPACE) for entry in entries]
                    assert locations == [ORIGIN + path for path in STATIC_PATHS] + [
                        ORIGIN + '/pages/post.php?id=' + str(post_id) for post_id in ids
                    ]
                    last_modified = {
                        location: entry.findtext('s:lastmod', namespaces=NAMESPACE)
                        for location, entry in zip(locations, entries)
                    }
                    for path in STATIC_PATHS:
                        assert last_modified[ORIGIN + path] is None
                    for post_id, expected_date in dates.items():
                        assert last_modified[ORIGIN + '/pages/post.php?id=' + str(post_id)] == expected_date
                    assert str(directory).encode() not in body
                    assert b'SQLSTATE' not in body
                    new_log = log_path.read_text(encoding='utf-8')[len(previous_log):]
                    assert ('Sitemap database read failed.' in new_log) == expect_error
                    assert str(database) not in new_log and 'SQLSTATE' not in new_log

                normal_dates = {
                    1: '2026-09-09T10:00:00+00:00',
                    2: '2026-09-08T16:30:00+00:00',
                    3: None, 4: None, 5: None,
                }
                before = database_state(database)
                request([1, 2, 3, 4, 5], normal_dates)
                assert database_state(database) == before, 'Normal requests must not alter the database'

                original_mode = database.stat().st_mode
                database.chmod(stat.S_IREAD)
                try:
                    before = database_state(database)
                    request([1, 2, 3, 4, 5], normal_dates)
                    assert database_state(database) == before, 'Read-only databases remain unchanged'
                finally:
                    database.chmod(original_mode)

                create_database(database, updated_at=False)
                before = database_state(database)
                request([1, 2], {1: '2026-09-08T16:30:00+00:00', 2: None})
                assert database_state(database) == before, 'Legacy columns and tables remain unchanged'

                database.unlink()
                before = database_state(database)
                request([], {}, expect_error=True)
                assert database_state(database) == before and not database.exists(), 'Missing database is not created'

                database.write_bytes(b'This is not a SQLite database.')
                before = database_state(database)
                request([], {}, expect_error=True)
                assert database_state(database) == before, 'Corrupt database is not replaced'

                database.unlink()
                database.mkdir()
                request([], {}, expect_error=True)
                assert database.is_dir() and not list(database.iterdir())
                database.rmdir()

                create_database(database)
                before = database_state(database)
                connection = sqlite3.connect(database)
                try:
                    connection.execute('BEGIN EXCLUSIVE')
                    request([], {}, expect_error=True)
                finally:
                    connection.rollback()
                    connection.close()
                assert database_state(database) == before, 'Locked database remains unchanged'

                with closing(sqlite3.connect(database)) as connection:
                    connection.execute('DELETE FROM posts')
                    connection.commit()
                before = database_state(database)
                request([], {})
                assert database_state(database) == before, 'Empty database is not seeded'
                print('Sitemap: HTTP/XML, dates, legacy schema, missing/corrupt/read-only/locked databases and database preservation checks passed.')
            finally:
                server.terminate()
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait()


if __name__ == '__main__':
    run()
