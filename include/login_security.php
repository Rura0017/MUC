<?php

declare(strict_types=1);

const LOGIN_ATTEMPT_WINDOW = 900;

function loginAccountBucket(string $username): string
{
    return 'account:' . hash('sha256', $username);
}

/** セッションや送信元を変えても、同一アカウントの試行回数を共有する。 */
function reserveLoginAttempt(PDO $pdo, string $username, string $address, ?int $now = null): int
{
    $now ??= time();
    $buckets = [
        loginAccountBucket($username) => 10,
        'ip:' . hash('sha256', $address) => 50,
    ];
    // 検査と加算を一つの書き込みトランザクションにする。
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $cleanup = $pdo->prepare('DELETE FROM login_attempts WHERE expires_at <= ?');
        $cleanup->execute([$now]);
        $read = $pdo->prepare('SELECT attempts, expires_at FROM login_attempts WHERE bucket = ?');
        $retryAfter = 0;
        foreach ($buckets as $bucket => $limit) {
            $read->execute([$bucket]);
            $row = $read->fetch(PDO::FETCH_ASSOC);
            if ($row && (int) $row['attempts'] >= $limit) {
                $retryAfter = max($retryAfter, (int) $row['expires_at'] - $now);
            }
        }
        if ($retryAfter === 0) {
            $write = $pdo->prepare(
                'INSERT INTO login_attempts (bucket, attempts, expires_at) VALUES (?, 1, ?)
                 ON CONFLICT(bucket) DO UPDATE SET attempts = attempts + 1'
            );
            foreach ($buckets as $bucket => $limit) {
                $write->execute([$bucket, $now + LOGIN_ATTEMPT_WINDOW]);
            }
        }
        $pdo->exec('COMMIT');
        return $retryAfter;
    } catch (Throwable $error) {
        $pdo->exec('ROLLBACK');
        throw $error;
    }
}

function clearLoginAccountAttempts(PDO $pdo, string $username): void
{
    $statement = $pdo->prepare('DELETE FROM login_attempts WHERE bucket = ?');
    $statement->execute([loginAccountBucket($username)]);
}
