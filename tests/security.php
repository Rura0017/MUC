<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../include/csrf.php';
require_once __DIR__ . '/../include/login_security.php';
require_once __DIR__ . '/../include/post_renderer.php';
require_once __DIR__ . '/../include/post_attachments.php';

$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $checks++;
}

startSession();
check(ini_get('session.use_strict_mode') === '1', 'strict sessions');
check(ini_get('session.use_only_cookies') === '1', 'cookie-only sessions');
check(session_get_cookie_params()['httponly'], 'HttpOnly');
check(session_get_cookie_params()['secure'], 'Secure behind proxy by default');
$token = csrfToken();
check(verifyCsrfToken($token), 'valid CSRF');
foreach ([null, [], ['nested'], '', str_repeat('0', 64), 42] as $invalid) {
    check(!verifyCsrfToken($invalid), 'invalid CSRF rejected without TypeError');
}
$_SESSION['admin_id'] = 1;
$_SESSION['admin_authenticated_at'] = time() - 28801;
$_SESSION['admin_last_activity'] = time();
check(!isAdminSessionValid(), 'absolute session expiry');
$_SESSION['admin_id'] = 1;
$_SESSION['admin_authenticated_at'] = time();
$_SESSION['admin_last_activity'] = time() - 1801;
check(!isAdminSessionValid(), 'idle session expiry');
session_write_close();

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE login_attempts (bucket TEXT PRIMARY KEY, attempts INTEGER, expires_at INTEGER)');
for ($i = 0; $i < 10; $i++) {
    check(reserveLoginAttempt($pdo, 'test-admin', '192.0.2.' . $i, 1000) === 0, 'allowed attempt');
}
check(reserveLoginAttempt($pdo, 'test-admin', '198.51.100.1', 1001) === 899, 'account throttled across IPs');
check(reserveLoginAttempt($pdo, 'test-admin', '198.51.100.1', 1100) === 800, 'blocked requests do not extend lockout');
check(reserveLoginAttempt($pdo, 'test-admin', '198.51.100.1', 1900) === 0, 'window expires');
for ($i = 0; $i < 50; $i++) {
    check(reserveLoginAttempt($pdo, 'name-' . $i, '203.0.113.1', 2000) === 0, 'IP budget');
}
check(reserveLoginAttempt($pdo, 'another-name', '203.0.113.1', 2001) === 899, 'IP throttled across accounts');
clearLoginAccountAttempts($pdo, 'name-0');
check(reserveLoginAttempt($pdo, 'name-0', '203.0.113.1', 2001) > 0, 'success cannot reset IP budget');

foreach (['javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', '//evil.example'] as $url) {
    check(normalizeQuillLink($url) === null, 'unsafe link scheme');
}
$body = normalizeQuillBody(json_encode(['ops' => [
    ['insert' => '<img src=x onerror=alert(1)>', 'attributes' => [
        'bold' => true, 'link' => 'javascript:alert(1)', 'color' => 'red;position:fixed',
    ]], ['insert' => "\n"],
]], JSON_THROW_ON_ERROR));
$html = renderQuillPostBody($body);
check(!str_contains($html, '<img') && !str_contains($html, 'href='), 'rich content cannot inject markup');
check(str_contains($html, '<strong>'), 'valid formatting retained');
check(!str_contains(renderPlainPostBody('<script>alert(1)</script>'), '<script>'), 'plain content escaped');
check(!isStoredInlineImageUrl('/image/uploads/../../secret.php'), 'inline traversal rejected');
$rejected = false;
try {
    normalizeQuillBody('{"ops":[{"insert":{"image":"data:image/svg+xml,<svg onload=alert(1)>"}}]}');
} catch (RuntimeException) {
    $rejected = true;
}
check($rejected, 'unsafe image rejected');

$file = tempnam(sys_get_temp_dir(), 'muc-upload-');
try {
    file_put_contents($file, "GIF89a<?php echo 'payload';");
    check(detectPostAttachmentMimeType($file) === null, 'forged image header rejected');
    file_put_contents($file, '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
    check(detectPostAttachmentMimeType($file) === null, 'SVG rejected');
    file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aK1cAAAAASUVORK5CYII='));
    check(detectPostAttachmentMimeType($file) === 'image/png', 'valid PNG accepted');
} finally {
    unlink($file);
}
$rejected = false;
try {
    collectPostAttachments(['error' => [['nested']], 'name' => [[]], 'tmp_name' => [[]], 'size' => [[]]]);
} catch (RuntimeException) {
    $rejected = true;
}
check($rejected, 'nested upload rejected');

echo "PASS: {$checks} security checks\n";
