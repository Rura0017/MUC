"""Integration checks in a temporary site and database; never touches live data.

Run: python tests/security_http.py (PHP 8.1+ with pdo_sqlite/mbstring on PATH).
"""
import base64
from contextlib import closing
import http.cookiejar
import os
from pathlib import Path
import re
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def run():
    with tempfile.TemporaryDirectory(prefix='muc-security-') as temp:
        temp = Path(temp)
        site = temp / 'site'
        shutil.copytree(ROOT, site, ignore=shutil.ignore_patterns('.git', 'storage', 'backup', '__pycache__'))
        (site / 'storage').mkdir()
        (site / 'image/uploads').mkdir(exist_ok=True)
        sessions = temp / 'sessions'
        sessions.mkdir()
        database = temp / 'private.sqlite'
        sqlite3.connect(database).close()
        env = dict(os.environ, MUC_DATABASE_PATH=str(database), MUC_SESSION_SECURE='0')
        seed = temp / 'seed.php'
        seed.write_text('''<?php
require $argv[1] . '/include/db.php';
$pdo = db();
$stmt = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)');
$stmt->execute(['security-test', password_hash('test-only-password-123', PASSWORD_DEFAULT)]);
''', encoding='utf-8')
        subprocess.run([PHP, str(seed), str(site)], env=env, check=True, capture_output=True)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        origin = f'http://127.0.0.1:{port}'
        cookies = http.cookiejar.CookieJar()
        client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies), NoRedirect())

        def request(path, fields=None, payload=None, headers=None):
            if fields is not None:
                payload = urllib.parse.urlencode(fields).encode()
            req = urllib.request.Request(origin + path, data=payload, headers=headers or {})
            try:
                response = client.open(req, timeout=10)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                return response.status, response.headers, response.read().decode('utf-8', errors='replace')

        def token(path):
            status, headers, body = request(path)
            assert status == 200, (path, status)
            assert headers['X-Content-Type-Options'] == 'nosniff'
            assert headers['X-Frame-Options'] == 'DENY'
            return re.search(r'name="csrf_token"\s+value="([0-9a-f]{64})"', body)[1]

        def multipart(fields, content):
            boundary = 'muc-security-test-boundary'
            data = bytearray()
            for name, value in fields.items():
                data.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
            data.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="inline_images[test]"; filename="evil.php"\r\nContent-Type: image/png\r\n\r\n'.encode())
            data.extend(content)
            data.extend(f'\r\n--{boundary}--\r\n'.encode())
            return bytes(data), {'Content-Type': 'multipart/form-data; boundary=' + boundary}

        with (temp / 'server.log').open('wb') as log:
            server = subprocess.Popen([PHP, '-d', 'session.save_path="' + sessions.as_posix() + '"', '-S', f'127.0.0.1:{port}', '-t', str(site)],
                                      env=env, stdout=log, stderr=log,
                                      creationflags=0x08000000 if os.name == 'nt' else 0)
            try:
                for _ in range(100):
                    try:
                        request('/pages/login.php')
                        break
                    except urllib.error.URLError:
                        time.sleep(.05)
                else:
                    raise RuntimeError('PHP server did not start')
                assert request('/admin/create_post.php')[0] == 302
                assert request('/admin/delete_post.php', {'post_id': '1'})[0] == 302
                assert request('/admin/change_password.php', {'new_password': 'unauthenticated'})[0] == 302
                assert request('/pages/login.php', {'username': 'security-test', 'password': 'test-only-password-123'})[0] == 403
                csrf = token('/pages/login.php')
                assert request('/pages/login.php', {'csrf_token[]': csrf})[0] == 403
                invalid = request('/pages/login.php', {'csrf_token': csrf, 'username[]': 'x'})
                assert invalid[0] == 400, (invalid[0], invalid[2][:200])
                before = next(c.value for c in cookies if c.name == 'PHPSESSID')
                status, _, _ = request('/pages/login.php', {'csrf_token': csrf, 'username': 'security-test', 'password': 'test-only-password-123'})
                assert status == 302
                after = next(c.value for c in cookies if c.name == 'PHPSESSID')
                assert before != after, 'login must rotate the session ID'
                csrf = token('/admin/create_post.php')
                assert request('/admin/create_post.php', {'csrf_token': csrf, 'title[]': 'bad'})[0] == 400
                assert request('/admin/delete_post.php')[0] == 405
                assert request('/admin/delete_post.php', {'csrf_token[]': csrf, 'post_id': '1'})[0] == 403
                assert request('/logout.php', {'csrf_token': 'bad'})[0] == 403
                # Real multipart requests exercise is_uploaded_file/move_uploaded_file.
                fields = {'csrf_token': csrf, 'title': '<script>alert(1)</script>', 'body_format': 'quill',
                          'body': '{"ops":[{"insert":"Japanese text"},{"insert":{"image":"upload:test"}},{"insert":"\\n"}]}'}
                payload, headers = multipart(fields, b'GIF89a<?php echo "bad";')
                assert request('/admin/create_post.php', payload=payload, headers=headers)[0] == 200
                with closing(sqlite3.connect(database)) as db:
                    assert db.execute("SELECT count(*) FROM posts WHERE post_type='post'").fetchone()[0] == 0
                png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK1cAAAAASUVORK5CYII=')
                payload, headers = multipart(fields, png)
                assert request('/admin/create_post.php', payload=payload, headers=headers)[0] == 302
                with closing(sqlite3.connect(database)) as db:
                    post_id, filename = db.execute('SELECT post_id, stored_name FROM post_attachments').fetchone()
                assert re.fullmatch(r'[a-f0-9]{32}\.png', filename)
                assert (site / 'image/uploads' / filename).read_bytes() == png
                status, _, body = request('/pages/post.php?id=' + str(post_id))
                assert status == 200 and '<script>alert(1)</script>' not in body
                assert request('/api/latest_post.php')[0] == 200
                assert request('/admin/delete_post.php', {'csrf_token': csrf, 'post_id': str(post_id)})[0] == 302
                assert not (site / 'image/uploads' / filename).exists()
                assert request('/logout.php', {'csrf_token': csrf})[0] == 302
                assert request('/admin/create_post.php')[0] == 302
                csrf = token('/pages/login.php')
                assert request('/pages/login.php', {'csrf_token': csrf, 'username': 'security-test', 'password': 'test-only-password-123'})[0] == 302
                # Password changes use the authenticated account, current password and two matching inputs.
                main_client = client
                other_cookies = http.cookiejar.CookieJar()
                client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(other_cookies), NoRedirect())
                other_csrf = token('/pages/login.php')
                assert request('/pages/login.php', {'csrf_token': other_csrf, 'username': 'security-test', 'password': 'test-only-password-123'})[0] == 302
                other_client = client
                client = main_client
                csrf = token('/admin/index.php')
                assert request('/admin/change_password.php')[0] == 405
                assert request('/admin/change_password.php', {})[0] == 403
                assert request('/admin/change_password.php', {'csrf_token[]': csrf})[0] == 403
                assert request('/admin/change_password.php', {'csrf_token': csrf, 'current_password[]': 'bad'})[0] == 400

                def change_password(current, new, confirmation=None, **extra):
                    return request('/admin/change_password.php', {
                        'csrf_token': csrf, 'current_password': current, 'new_password': new,
                        'confirm_password': new if confirmation is None else confirmation, **extra})

                with closing(sqlite3.connect(database)) as db:
                    original_hash = db.execute("SELECT password_hash FROM admins WHERE username='security-test'").fetchone()[0]
                invalid_changes = [
                    ('test-only-password-123', 'a-new-password-123', 'different-password'),
                    ('test-only-password-123', 'short', None),
                    ('test-only-password-123', 'N' * 73, None),
                    ('test-only-password-123', '日' * 25, None),
                    ('test-only-password-123', '日' * 11, None),
                    ('test-only-password-123', 'a-new-password\x00', None),
                    ('wrong-current', 'a-new-password-123', None),
                    ('test-only-password-123', 'test-only-password-123', None),
                ]
                for current, new, confirmation in invalid_changes:
                    status, headers, _ = change_password(current, new, confirmation)
                    assert status == 303 and headers['Location'] == 'index.php#password-settings'
                    _, _, result = request('/admin/index.php')
                    assert 'role="alert"' in result and 'value="' + new + '"' not in result
                    with closing(sqlite3.connect(database)) as db:
                        assert db.execute("SELECT password_hash FROM admins WHERE username='security-test'").fetchone()[0] == original_hash

                new_password = '新しいパスワード確認用文字123'
                before = next(c.value for c in cookies if c.name == 'PHPSESSID')
                assert change_password('test-only-password-123', new_password, username='someone-else', admin_id='999')[0] == 303
                after = next(c.value for c in cookies if c.name == 'PHPSESSID')
                assert before != after, 'password change must rotate the session ID'
                _, _, result = request('/admin/index.php')
                assert 'role="status"' in result and new_password not in result
                assert 'role="status"' not in request('/admin/index.php')[2], 'success message is single-use'
                assert change_password(new_password, 'another-password-123')[0] == 403, 'old CSRF token must expire'
                csrf = token('/admin/index.php')
                client = other_client
                assert request('/admin/index.php')[0] == 302, 'other session must be revoked'
                old_login_csrf = token('/pages/login.php')
                assert request('/pages/login.php', {'csrf_token': old_login_csrf, 'username': 'security-test', 'password': 'test-only-password-123'})[0] == 200
                client = main_client
                assert request('/logout.php', {'csrf_token': csrf})[0] == 302
                csrf = token('/pages/login.php')
                assert request('/pages/login.php', {'csrf_token': csrf, 'username': 'security-test', 'password': new_password})[0] == 302
                csrf = token('/admin/index.php')
                # Accept the bcrypt boundary and preserve intentional whitespace.
                boundary_password = ' ' + 'N' * 70 + ' '
                assert change_password(new_password, boundary_password)[0] == 303
                csrf = token('/admin/index.php')
                assert request('/logout.php', {'csrf_token': csrf})[0] == 302
                csrf = token('/pages/login.php')
                assert request('/pages/login.php', {'csrf_token': csrf, 'username': 'security-test', 'password': boundary_password})[0] == 302
                csrf = token('/admin/index.php')
                # Start a fresh attempt window in the isolated DB, then exhaust it.
                with closing(sqlite3.connect(database)) as db:
                    db.execute('UPDATE login_attempts SET expires_at = 0')
                    db.commit()
                for _ in range(10):
                    assert change_password('wrong-current', 'another-password-123')[0] == 303
                # A separate authenticated browser cannot bypass the account limit.
                client = other_client
                login_csrf = token('/pages/login.php')
                assert request('/pages/login.php', {'csrf_token': login_csrf, 'username': 'security-test', 'password': boundary_password})[0] == 302
                csrf = token('/admin/index.php')
                assert change_password(boundary_password, 'another-password-123')[0] == 303
                assert '試行回数が多すぎます' in request('/admin/index.php')[2]
                with closing(sqlite3.connect(database)) as db:
                    db.execute('UPDATE login_attempts SET expires_at = 0')
                    db.commit()
                assert change_password(boundary_password, 'another-password-123')[0] == 303
                assert 'role="status"' in request('/admin/index.php')[2]
                client = main_client
                assert request('/admin/index.php')[0] == 302
                csrf = token('/pages/login.php')
                assert request('/pages/login.php', {'csrf_token': csrf, 'username': 'security-test', 'password': 'another-password-123'})[0] == 302
                # Password changes invalidate existing authenticated sessions.
                with closing(sqlite3.connect(database)) as db:
                    db.execute("UPDATE admins SET password_hash='changed' WHERE username='security-test'")
                    db.commit()
                assert request('/admin/create_post.php')[0] == 302
                for i in range(10):
                    csrf = token('/pages/login.php')
                    assert request('/pages/login.php', {'csrf_token': csrf, 'username': 'missing-user', 'password': 'wrong'})[0] == 200
                cookies.clear()  # Rate limiting must survive a new session.
                csrf = token('/pages/login.php')
                status, headers, _ = request('/pages/login.php', {'csrf_token': csrf, 'username': 'missing-user', 'password': 'wrong'})
                assert status == 429 and int(headers['Retry-After']) > 0
                print('PASS: HTTP auth, password changes, CSRF, malformed input, session rotation/revocation, upload, escaped output, deletion and rate limits')
            finally:
                server.terminate()
                server.wait(timeout=10)


if __name__ == '__main__':
    run()
